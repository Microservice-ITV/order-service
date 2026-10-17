<?php

namespace Tests\Unit\Services;

use App\Enums\EventTopic;
use App\Enums\SagaStep;
use App\Exceptions\SagaException;
use App\Models\SagaState;
use App\Repositories\SagaRepository;
use App\Services\SagaOrchestrator;
use App\Services\RabbitMQ\Publisher;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Mockery;
use Tests\TestCase;

/**
 * @covers \App\Services\SagaOrchestrator
 */
class SagaOrchestratorTest extends TestCase
{
    private SagaOrchestrator $sagaOrchestrator;
    private SagaRepository $sagaRepository;
    private Publisher $eventPublisher;

    private function makeSaga(array $attributes = []): SagaState
    {
        $saga = new class extends SagaState {
            public bool $startCalled = false;

            public function start(): void
            {
                $this->startCalled = true;
            }
        };

        foreach ($attributes as $key => $value) {
            $saga->setAttribute($key, $value);
        }

        return $saga;
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->sagaRepository = Mockery::mock(SagaRepository::class);
        $this->eventPublisher = Mockery::mock(Publisher::class);
        
        $this->sagaOrchestrator = new SagaOrchestrator(
            $this->sagaRepository,
            $this->eventPublisher
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_creates_and_starts_a_new_saga(): void
    {
        $sagaId = 'test-saga-id';
        $data = [
            'saga_id' => $sagaId,
            'order_id' => 'test-order-id',
            'correlation_id' => 'test-correlation-id',
            'data' => ['items' => [['product_id' => 'prod-1', 'qty' => 1]]],
        ];

        $createdSaga = $this->makeSaga(['saga_id' => $sagaId]);
        $this->sagaRepository->shouldReceive('findById')
            ->once()
            ->with($sagaId)
            ->andReturn(null);
        $this->sagaRepository->shouldReceive('create')
            ->once()
            ->andReturn($createdSaga);
        $this->eventPublisher->shouldNotReceive('publish');

        $result = $this->sagaOrchestrator->startSaga('ORDER_CREATION', $data);

        $this->assertEquals($sagaId, $result);
        $this->assertTrue($createdSaga->startCalled);
    }

    /** @test */
    public function it_executes_next_step_in_saga(): void
    {
        $sagaId = 'test-saga-id';
        $saga = $this->makeSaga([
            'saga_id' => $sagaId,
            'status' => SagaState::STATUS_IN_PROGRESS,
            'completed_steps' => [],
            'total_steps' => 2,
            'data' => ['items' => [['product_id' => 'prod-1', 'qty' => 1]]],
        ]);

        // Mock repository
        $this->sagaRepository->shouldReceive('findById')
            ->once()
            ->andReturn($saga);

        // Mock event publisher
        $this->eventPublisher->shouldReceive('publish')
            ->once()
            ->with(EventTopic::SAGA_INVENTORY_RESERVATION_REQUESTED, Mockery::on(function ($payload) use ($sagaId) {
                return $payload['saga_id'] === $sagaId && 
                       $payload['step'] === 'INVENTORY_RESERVATION';
            }));

        $this->sagaOrchestrator->executeNextStep($sagaId);
        $this->assertSame($sagaId, $saga->saga_id);
    }

    /** @test */
    public function it_completes_a_step_and_executes_next(): void
    {
        $sagaId = 'test-saga-id';
        $stepName = 'INVENTORY_RESERVATION';
        
        // Mock saga
        $saga = $this->makeSaga([
            'saga_id' => $sagaId,
            'data' => ['items' => [['product_id' => 'prod-1', 'qty' => 1]]],
        ]);

        // Mock repository
        $this->sagaRepository->shouldReceive('findById')
            ->twice()
            ->andReturn($saga, $saga);

        $this->sagaRepository->shouldReceive('completeStep')
            ->once()
            ->with($sagaId, $stepName)
            ->andReturnUsing(function () use ($saga): bool {
                $saga->completed_steps = ['INVENTORY_RESERVATION'];

                return true;
            });

        // Mock event publisher for completion event
        $this->eventPublisher->shouldReceive('publish')
            ->once()
            ->with(EventTopic::SAGA_INVENTORY_RESERVATION_COMPLETED, Mockery::on(function ($payload) use ($sagaId) {
                return $payload['saga_id'] === $sagaId;
            }));

        $this->eventPublisher->shouldReceive('publish')
            ->once()
            ->with(EventTopic::SAGA_PAYMENT_REQUESTED, Mockery::on(function ($payload) use ($sagaId) {
                return $payload['saga_id'] === $sagaId
                    && $payload['step'] === 'PAYMENT_PROCESSING';
            }));

        $this->sagaOrchestrator->completeStep($sagaId, $stepName);
        $this->assertSame(['INVENTORY_RESERVATION'], $saga->completed_steps);
    }

    /** @test */
    public function it_handles_step_failure(): void
    {
        $sagaId = 'test-saga-id';
        $stepName = 'INVENTORY_RESERVATION';
        $errorMessage = 'Inventory not available';

        // Mock saga
        $saga = $this->makeSaga([
            'saga_id' => $sagaId,
            'data' => ['items' => [['product_id' => 'prod-1', 'qty' => 1]]],
        ]);

        // Mock repository
        $this->sagaRepository->shouldReceive('findById')
            ->once()
            ->andReturn($saga);

        $this->sagaRepository->shouldReceive('failStep')
            ->once()
            ->with($sagaId, $stepName, $errorMessage)
            ->andReturnTrue();

        // Mock event publisher for failure event
        $this->eventPublisher->shouldReceive('publish')
            ->once()
            ->with(EventTopic::SAGA_INVENTORY_RESERVATION_FAILED, Mockery::on(function ($payload) use ($sagaId, $errorMessage) {
                return $payload['saga_id'] === $sagaId && 
                       $payload['error'] === $errorMessage;
            }));

        $this->sagaOrchestrator->failStep($sagaId, $stepName, $errorMessage);
        $this->assertSame($sagaId, $saga->saga_id);
    }

    /** @test */
    public function it_throws_exception_for_unknown_saga(): void
    {
        $this->sagaRepository->shouldReceive('findById')
            ->once()
            ->andReturn(null);

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('Saga not found: unknown-saga-id');

        $this->sagaOrchestrator->executeNextStep('unknown-saga-id');
    }

    /** @test */
    public function it_processes_timed_out_sagas(): void
    {
        $timedOutSagas = new EloquentCollection([$this->makeSaga([
            'saga_id' => 'timeout-saga-1',
            'timeout_at' => now()->subHour(),
            'status' => SagaState::STATUS_IN_PROGRESS,
        ])]);

        $this->sagaRepository->shouldReceive('getTimedOutSagas')
            ->once()
            ->andReturn($timedOutSagas);

        $this->sagaRepository->shouldReceive('update')
            ->once()
            ->with('timeout-saga-1', Mockery::on(function ($data) {
                return $data['status'] === SagaState::STATUS_TIMEOUT;
            }));

        $this->eventPublisher->shouldReceive('publish')
            ->once()
            ->with(EventTopic::SAGA_FAILED, Mockery::on(function ($payload) {
                return $payload['saga_id'] === 'timeout-saga-1' &&
                       $payload['error'] === 'Saga timeout';
            }));

        $this->sagaOrchestrator->processTimeouts();
        $this->assertCount(1, $timedOutSagas);
    }

    /** @test */
    public function it_gets_saga_execution_order(): void
    {
        $steps = SagaStep::getExecutionOrder();
        
        $this->assertIsArray($steps);
        $this->assertCount(2, $steps);
        $this->assertArrayHasKey('INVENTORY_RESERVATION', $steps);
        $this->assertArrayHasKey('PAYMENT_PROCESSING', $steps);
        
        // Verify order
        $stepNames = array_keys($steps);
        $this->assertEquals('INVENTORY_RESERVATION', $stepNames[0]);
        $this->assertEquals('PAYMENT_PROCESSING', $stepNames[1]);
    }

    /** @test */
    public function it_gets_compensation_order(): void
    {
        $steps = SagaStep::getCompensationOrder();
        
        $this->assertIsArray($steps);
        $this->assertCount(2, $steps);
        $this->assertArrayHasKey('PAYMENT_CANCELLATION', $steps);
        $this->assertArrayHasKey('INVENTORY_RELEASE', $steps);
        
        // Verify reverse order
        $stepNames = array_keys($steps);
        $this->assertEquals('PAYMENT_CANCELLATION', $stepNames[0]);
        $this->assertEquals('INVENTORY_RELEASE', $stepNames[1]);
    }
}
