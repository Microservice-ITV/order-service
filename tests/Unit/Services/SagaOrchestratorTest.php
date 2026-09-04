<?php

namespace Tests\Unit\Services;

use App\Enums\EventTopic;
use App\Enums\SagaStep;
use App\Exceptions\SagaException;
use App\Models\SagaState;
use App\Repositories\SagaRepository;
use App\Services\SagaOrchestrator;
use App\Services\RabbitMQ\Publisher;
use Illuminate\Support\Facades\Event;
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
            'order_id' => 'test-order-id',
            'correlation_id' => 'test-correlation-id',
            'data' => ['items' => [['product_id' => 'prod-1', 'qty' => 1]]],
        ];

        // Mock saga repository
        $this->sagaRepository->shouldReceive('create')
            ->once()
            ->andReturn(Mockery::mock(SagaState::class, function ($mock) use ($sagaId) {
                $mock->saga_id = $sagaId;
                $mock->shouldReceive('start')->once();
            }));

        $this->eventPublisher->shouldReceive('publish')
            ->once()
            ->with(EventTopic::SAGA_ORDER_CREATED, Mockery::on(function ($payload) use ($sagaId, $data) {
                return $payload['saga_id'] === $sagaId && 
                       $payload['data'] === $data['data'];
            }));

        $result = $this->sagaOrchestrator->startSaga('ORDER_CREATION', $data);

        $this->assertEquals($sagaId, $result);
    }

    /** @test */
    public function it_executes_next_step_in_saga(): void
    {
        $sagaId = 'test-saga-id';
        $saga = Mockery::mock(SagaState::class);
        $saga->saga_id = $sagaId;
        $saga->status = SagaState::STATUS_IN_PROGRESS;
        $saga->completed_steps = [];
        $saga->total_steps = 3;
        $saga->data = ['items' => [['product_id' => 'prod-1', 'qty' => 1]]];

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
    }

    /** @test */
    public function it_completes_a_step_and_executes_next(): void
    {
        $sagaId = 'test-saga-id';
        $stepName = 'INVENTORY_RESERVATION';
        
        // Mock saga
        $saga = Mockery::mock(SagaState::class);
        $saga->saga_id = $sagaId;
        $saga->data = ['items' => [['product_id' => 'prod-1', 'qty' => 1]]];

        // Mock repository
        $this->sagaRepository->shouldReceive('findById')
            ->once()
            ->andReturn($saga);

        $this->sagaRepository->shouldReceive('completeStep')
            ->once()
            ->with($sagaId, $stepName);

        // Mock event publisher for completion event
        $this->eventPublisher->shouldReceive('publish')
            ->once()
            ->with(EventTopic::SAGA_INVENTORY_RESERVATION_COMPLETED, Mockery::on(function ($payload) use ($sagaId) {
                return $payload['saga_id'] === $sagaId;
            }));

        // Mock execute next step
        $this->sagaOrchestrator->shouldReceive('executeNextStep')
            ->once()
            ->with($sagaId);

        $this->sagaOrchestrator->completeStep($sagaId, $stepName);
    }

    /** @test */
    public function it_handles_step_failure(): void
    {
        $sagaId = 'test-saga-id';
        $stepName = 'INVENTORY_RESERVATION';
        $errorMessage = 'Inventory not available';

        // Mock saga
        $saga = Mockery::mock(SagaState::class);
        $saga->saga_id = $sagaId;
        $saga->data = ['items' => [['product_id' => 'prod-1', 'qty' => 1]]];

        // Mock repository
        $this->sagaRepository->shouldReceive('findById')
            ->once()
            ->andReturn($saga);

        $this->sagaRepository->shouldReceive('failStep')
            ->once()
            ->with($sagaId, $stepName, $errorMessage);

        // Mock event publisher for failure event
        $this->eventPublisher->shouldReceive('publish')
            ->once()
            ->with(EventTopic::SAGA_INVENTORY_RESERVATION_FAILED, Mockery::on(function ($payload) use ($sagaId, $errorMessage) {
                return $payload['saga_id'] === $sagaId && 
                       $payload['error'] === $errorMessage;
            }));

        $this->sagaOrchestrator->failStep($sagaId, $stepName, $errorMessage);
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
        $timedOutSagas = collect([
            Mockery::mock(SagaState::class, function ($mock) {
                $mock->saga_id = 'timeout-saga-1';
                $mock->timeout_at = now()->subHour();
                $mock->status = SagaState::STATUS_IN_PROGRESS;
            })
        ]);

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
    }

    /** @test */
    public function it_gets_saga_execution_order(): void
    {
        $steps = SagaStep::getExecutionOrder();
        
        $this->assertIsArray($steps);
        $this->assertCount(3, $steps);
        $this->assertArrayHasKey('INVENTORY_RESERVATION', $steps);
        $this->assertArrayHasKey('PAYMENT_PROCESSING', $steps);
        $this->assertArrayHasKey('SHIPMENT_CREATION', $steps);
        
        // Verify order
        $stepNames = array_keys($steps);
        $this->assertEquals('INVENTORY_RESERVATION', $stepNames[0]);
        $this->assertEquals('PAYMENT_PROCESSING', $stepNames[1]);
        $this->assertEquals('SHIPMENT_CREATION', $stepNames[2]);
    }

    /** @test */
    public function it_gets_compensation_order(): void
    {
        $steps = SagaStep::getCompensationOrder();
        
        $this->assertIsArray($steps);
        $this->assertCount(3, $steps);
        $this->assertArrayHasKey('SHIPMENT_CANCELLATION', $steps);
        $this->assertArrayHasKey('PAYMENT_CANCELLATION', $steps);
        $this->assertArrayHasKey('INVENTORY_RELEASE', $steps);
        
        // Verify reverse order
        $stepNames = array_keys($steps);
        $this->assertEquals('SHIPMENT_CANCELLATION', $stepNames[0]);
        $this->assertEquals('PAYMENT_CANCELLATION', $stepNames[1]);
        $this->assertEquals('INVENTORY_RELEASE', $stepNames[2]);
    }
}