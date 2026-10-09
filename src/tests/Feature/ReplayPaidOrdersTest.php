<?php

namespace Tests\Feature;

use App\Services\RabbitMQ\Publisher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ReplayPaidOrdersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('outbox_events');
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 120);
            $table->string('exchange', 120)->nullable();
            $table->string('routing_key', 120);
            $table->json('payload');
            $table->string('status', 20)->default('PENDING');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_dry_run_reports_matches_without_inserting_replay_rows(): void
    {
        $this->insertSourceEvent('order-1', ['name' => 'snapshot']);
        $publisher = Mockery::mock(Publisher::class);
        $publisher->shouldNotReceive('publishOutboxRows');
        $this->app->instance(Publisher::class, $publisher);

        $this->artisan('orders:replay-paid', [
            '--order-id' => ['order-1'],
            '--queue' => 'order-read.events',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('1 order.paid event(s) would be enqueued')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('outbox_events')->count());
    }

    public function test_replay_copies_the_original_payload_with_a_fresh_id_to_only_the_target_queue(): void
    {
        $sourceId = $this->insertSourceEvent('order-1', ['name' => 'snapshot']);
        $source = DB::table('outbox_events')->where('id', $sourceId)->first();
        $sourcePayload = json_decode((string) $source->payload, true, 512, JSON_THROW_ON_ERROR);
        $publisher = Mockery::mock(Publisher::class);
        $publisher->shouldReceive('publishOutboxRows')
            ->once()
            ->with(Mockery::type('array'))
            ->andReturnUsing(function (array $ids): int {
                DB::table('outbox_events')->whereIn('id', $ids)->update(['status' => 'SENT']);

                return count($ids);
            });
        $this->app->instance(Publisher::class, $publisher);

        $this->artisan('orders:replay-paid', [
            '--order-id' => ['order-1'],
            '--queue' => 'order-read.events',
        ])
            ->expectsOutputToContain("Published 1 replay event(s) directly to 'order-read.events'")
            ->assertExitCode(0);

        $replay = DB::table('outbox_events')->where('id', '>', $sourceId)->first();
        $replayPayload = json_decode((string) $replay->payload, true, 512, JSON_THROW_ON_ERROR);

        $this->assertNotSame($sourcePayload['event_id'], $replayPayload['event_id']);
        $this->assertSame($sourcePayload['data'], $replayPayload['data']);
        $this->assertSame('order.paid', $replayPayload['event']);
        $this->assertNull($replay->exchange);
        $this->assertSame('order-read.events', $replay->routing_key);
        $this->assertSame('SENT', $replay->status);
        $this->assertSame(0, (int) $replay->attempt_count);
        $this->assertSame('SENT', $source->status);
    }

    public function test_all_replay_uses_only_the_latest_sent_event_per_order(): void
    {
        $this->insertSourceEvent('order-1', ['version' => 'old']);
        $this->insertSourceEvent('order-1', ['version' => 'latest']);
        $this->insertSourceEvent('order-2', ['version' => 'only']);
        $pendingId = $this->insertSourceEvent('order-3', ['version' => 'pending']);
        DB::table('outbox_events')->where('id', $pendingId)->update(['status' => 'PENDING']);

        $publisher = Mockery::mock(Publisher::class);
        $publisher->shouldReceive('publishOutboxRows')
            ->once()
            ->with(Mockery::type('array'))
            ->andReturnUsing(function (array $ids): int {
                DB::table('outbox_events')->whereIn('id', $ids)->update(['status' => 'SENT']);

                return count($ids);
            });
        $this->app->instance(Publisher::class, $publisher);

        $this->artisan('orders:replay-paid', [
            '--all' => true,
            '--queue' => 'order-read.events',
        ])->assertExitCode(0);

        $replayRows = DB::table('outbox_events')
            ->whereNull('exchange')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $replayRows);
        $payloads = $replayRows->map(
            fn (object $row): array => json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR),
        );

        $this->assertSame(['latest', 'only'], $payloads->pluck('data.version')->all());
    }

    public function test_command_requires_an_explicit_scope_and_target_queue(): void
    {
        $this->artisan('orders:replay-paid')
            ->expectsOutputToContain('Specify the read-service queue')
            ->assertExitCode(2);

        $this->artisan('orders:replay-paid', [
            '--queue' => 'order-read.events',
        ])
            ->expectsOutputToContain('Specify either one or more --order-id options, or --all.')
            ->assertExitCode(2);
    }

    private function insertSourceEvent(string $orderId, array $extraData): int
    {
        $now = now();
        $payload = [
            'event_id' => (string) Str::uuid(),
            'event' => 'order.paid',
            'version' => 'v1',
            'timestamp' => $now->toIso8601String(),
            'producer' => 'order-service',
            'correlation_id' => 'test-correlation',
            'data' => array_merge(['order_id' => $orderId], $extraData),
        ];

        return (int) DB::table('outbox_events')->insertGetId([
            'event_id' => $payload['event_id'],
            'event_type' => 'order.paid',
            'exchange' => 'order_events',
            'routing_key' => 'order.paid',
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'SENT',
            'attempt_count' => 1,
            'occurred_at' => $now,
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
