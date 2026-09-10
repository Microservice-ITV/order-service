<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Saga State Management
 * 
 * Tracks the state of sagas including their progress and compensation status.
 */
class SagaState extends Model
{
    use HasFactory;

    protected $table = 'saga_states';
    
    protected $fillable = [
        'saga_id',
        'type',
        'status',
        'order_id',
        'correlation_id',
        'data',
        'step_order',
        'total_steps',
        'current_step',
        'completed_steps',
        'failed_steps',
        'compensation_required',
        'compensation_status',
        'error_message',
        'started_at',
        'completed_at',
        'timeout_at',
    ];

    protected $casts = [
        'data' => 'json',
        'step_order' => 'integer',
        'total_steps' => 'integer',
        'completed_steps' => 'array',
        'failed_steps' => 'array',
        'compensation_required' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'timeout_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_COMPENSATING = 'COMPENSATING';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_TIMEOUT = 'TIMEOUT';

    public const TYPE_ORDER_CREATION = 'ORDER_CREATION';

    /**
     * Mark saga as started
     */
    public function start(): void
    {
        $this->update([
            'status' => self::STATUS_IN_PROGRESS,
            'started_at' => now(),
            'current_step' => 1,
        ]);
    }

    /**
     * Mark step as completed
     */
    public function completeStep(string $stepName): void
    {
        $completedSteps = $this->completed_steps ?? [];
        $completedSteps[] = $stepName;
        
        $this->update([
            'completed_steps' => $completedSteps,
            'current_step' => count($completedSteps),
        ]);

        $this->checkCompletion();
    }

    /**
     * Mark step as failed
     */
    public function failStep(string $stepName, string $errorMessage): void
    {
        $failedSteps = $this->failed_steps ?? [];
        $failedSteps[] = $stepName;
        
        $this->update([
            'failed_steps' => $failedSteps,
            'error_message' => $errorMessage,
        ]);

        if (!$this->compensation_required) {
            $this->triggerCompensation();
        }
    }

    /**
     * Trigger compensation
     */
    public function triggerCompensation(): void
    {
        $this->update([
            'compensation_required' => true,
            'status' => self::STATUS_COMPENSATING,
        ]);
    }

    /**
     * Mark compensation as completed
     */
    public function completeCompensation(): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'compensation_status' => 'COMPLETED',
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark saga as completed
     */
    public function complete(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Check if saga is completed
     */
    private function checkCompletion(): void
    {
        if ($this->completed_steps && count($this->completed_steps) >= $this->total_steps) {
            $this->complete();
        }
    }

    /**
     * Check if saga is timed out
     */
    public function isTimedOut(): bool
    {
        return $this->timeout_at && now()->isAfter($this->timeout_at);
    }

    /**
     * Scope for active sagas
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPENSATING
        ]);
    }

    /**
     * Scope for pending compensation
     */
    public function scopePendingCompensation($query)
    {
        return $query->where('compensation_required', true)
                    ->where('status', '!=', self::STATUS_FAILED);
    }
}