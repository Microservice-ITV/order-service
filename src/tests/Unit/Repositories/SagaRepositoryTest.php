<?php

namespace Tests\Unit\Repositories;

use App\Enums\SagaStep;
use App\Models\SagaState;
use App\Repositories\SagaRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SagaRepositoryTest extends TestCase
{
    private SagaRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('saga_states');
        Schema::create('saga_states', function (Blueprint $table): void {
            $table->id();
            $table->string('saga_id')->unique();
            $table->string('type');
            $table->string('status');
            $table->string('order_id')->nullable();
            $table->string('correlation_id')->nullable();
            $table->json('data')->nullable();
            $table->integer('step_order')->default(1);
            $table->integer('total_steps')->default(2);
            $table->integer('current_step')->nullable();
            $table->json('completed_steps')->nullable();
            $table->json('failed_steps')->nullable();
            $table->boolean('compensation_required')->default(false);
            $table->string('compensation_status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('timeout_at')->nullable();
            $table->timestamps();
        });

        $this->repository = new SagaRepository();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('saga_states');
        parent::tearDown();
    }

    public function test_saga_steps_are_ordered_idempotent_and_complete_after_payment(): void
    {
        $saga = $this->repository->create([
            'saga_id' => 'saga-order-1',
            'type' => SagaState::TYPE_ORDER_CREATION,
            'order_id' => 'checkout-1',
            'correlation_id' => 'checkout-1',
            'total_steps' => count(SagaStep::ORDER_CREATION_STEPS),
        ]);
        $this->repository->start($saga->saga_id);

        $this->assertFalse($this->repository->completeStep($saga->saga_id, 'PAYMENT_PROCESSING'));
        $this->assertTrue($this->repository->completeStep($saga->saga_id, 'INVENTORY_RESERVATION'));
        $this->assertTrue($this->repository->completeStep($saga->saga_id, 'INVENTORY_RESERVATION'));

        $saga->refresh();
        $this->assertSame(['INVENTORY_RESERVATION'], $saga->completed_steps);
        $this->assertSame(2, $saga->current_step);
        $this->assertSame(SagaState::STATUS_IN_PROGRESS, $saga->status);

        $this->assertTrue($this->repository->completeStep($saga->saga_id, 'PAYMENT_PROCESSING'));
        $saga->refresh();

        $this->assertSame(['INVENTORY_RESERVATION', 'PAYMENT_PROCESSING'], $saga->completed_steps);
        $this->assertSame(2, $saga->total_steps);
        $this->assertSame(SagaState::STATUS_COMPLETED, $saga->status);
        $this->assertNotNull($saga->completed_at);
    }
}
