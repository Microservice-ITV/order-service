<?php

namespace App\Console\Commands;

use App\Services\RabbitMQ\Publisher;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class ReplayPaidOrders extends Command
{
    protected $signature = 'orders:replay-paid
        {--order-id=* : One or more order IDs to replay (repeat the option)}
        {--all : Replay the latest published order.paid event for every order}
        {--queue= : Target order-read queue name, for example order-read.events}
        {--dry-run : Preview matching orders without enqueueing replay events}';

    protected $description = 'Replay order.paid projections directly to the ecommerce read-service queue';

    private const EVENT_TYPE = 'order.paid';

    private const INSERT_BATCH_SIZE = 100;

    private const QUERY_BATCH_SIZE = 200;

    public function handle(Publisher $publisher): int
    {
        $orderIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): string => trim((string) $id), $this->option('order-id')),
            static fn (string $id): bool => $id !== '',
        )));
        $queue = trim((string) $this->option('queue'));
        $all = (bool) $this->option('all');

        if ($queue === '') {
            $this->error('Specify the read-service queue with --queue (for example: --queue=order-read.events).');

            return self::INVALID;
        }

        if (($all && $orderIds !== []) || (! $all && $orderIds === [])) {
            $this->error('Specify either one or more --order-id options, or --all.');

            return self::INVALID;
        }

        $queuedCount = 0;
        $publishedCount = 0;

        try {
            $eventsQuery = $this->latestSentPaidEventsQuery($orderIds);
            $matchedCount = (clone $eventsQuery)->count();

            if ($matchedCount === 0) {
                $this->warn('No previously sent order.paid events matched the requested orders.');

                return $orderIds === [] ? self::SUCCESS : self::FAILURE;
            }

            if ($this->option('dry-run')) {
                $samples = (clone $eventsQuery)
                    ->orderBy('id')
                    ->limit(10)
                    ->get(['id', 'payload']);

                $this->info("Dry run: {$matchedCount} order.paid event(s) would be enqueued to queue '{$queue}'.");
                $this->line('Sample order IDs: '.$samples
                    ->map(fn (object $row): string => $this->orderIdFromPayload($row->payload))
                    ->filter()
                    ->implode(', '));
                $this->comment('No database changes were made.');

                return self::SUCCESS;
            }

            (clone $eventsQuery)
                ->orderBy('id')
                ->chunkById(self::QUERY_BATCH_SIZE, function ($events) use ($queue, $publisher, &$queuedCount, &$publishedCount): void {
                    $rows = [];
                    $now = now();

                    foreach ($events as $event) {
                        $rows[] = $this->buildReplayOutboxRow($event, $queue, $now);
                    }

                    if ($rows === []) {
                        return;
                    }

                    DB::transaction(static function () use ($rows): void {
                        foreach (array_chunk($rows, self::INSERT_BATCH_SIZE) as $batch) {
                            DB::table('outbox_events')->insert($batch);
                        }
                    });

                    $queuedCount += count($rows);

                    $eventIds = array_column($rows, 'event_id');
                    $outboxIds = DB::table('outbox_events')
                        ->whereIn('event_id', $eventIds)
                        ->pluck('id')
                        ->all();

                    $publisher->publishOutboxRows($outboxIds);
                    $sentCount = DB::table('outbox_events')
                        ->whereIn('id', $outboxIds)
                        ->where('status', 'SENT')
                        ->count();
                    $publishedCount += $sentCount;

                    if ($sentCount !== count($outboxIds)) {
                        throw new RuntimeException('One or more replay events remain pending or failed in the outbox.');
                    }
                }, 'id');

            $this->info("Published {$publishedCount} replay event(s) directly to '{$queue}'.");
            $this->comment('Confirm the read-service consumer is running and processing the queue.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            if ($queuedCount > 0) {
                $this->warn("{$queuedCount} replay event(s) were already queued before the failure; inspect the outbox before retrying.");
            }
            $this->error('Paid-order replay failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Pick the most recent successfully published order.paid snapshot per order.
     * Failed and pending source rows are excluded because the normal outbox job
     * already owns delivery retries for those original events.
     */
    private function latestSentPaidEventsQuery(array $orderIds): Builder
    {
        $latestIds = DB::table('outbox_events')
            ->selectRaw('MAX(id) as id')
            ->where('event_type', self::EVENT_TYPE)
            ->where('routing_key', self::EVENT_TYPE)
            ->where('status', 'SENT')
            ->whereNotNull('payload->data->order_id')
            ->when($orderIds !== [], static function ($query) use ($orderIds): void {
                $query->whereIn('payload->data->order_id', $orderIds);
            })
            ->groupBy('payload->data->order_id');

        return DB::table('outbox_events')
            ->whereIn('id', $latestIds);
    }

    /** @throws JsonException|RuntimeException */
    private function buildReplayOutboxRow(object $source, string $queue, mixed $now): array
    {
        $payload = is_array($source->payload)
            ? $source->payload
            : json_decode((string) $source->payload, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($payload)
            || ($payload['event'] ?? null) !== self::EVENT_TYPE
            || ! is_array($payload['data'] ?? null)
            || trim((string) ($payload['data']['order_id'] ?? '')) === '') {
            throw new RuntimeException("Outbox source row {$source->id} is not a valid order.paid event.");
        }

        // The consumer deduplicates by event_id. A fresh ID lets the projection
        // process this replay even when its processed-event record still exists.
        $payload['event_id'] = (string) Str::uuid();

        return [
            'event_id' => $payload['event_id'],
            'event_type' => self::EVENT_TYPE,
            // Empty exchange means RabbitMQ's built-in default exchange. Using
            // the queue name as routing key targets only the read projection.
            'exchange' => null,
            'routing_key' => $queue,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'PENDING',
            'attempt_count' => 0,
            'last_error' => null,
            'occurred_at' => $source->occurred_at ?? $now,
            'published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function orderIdFromPayload(mixed $payload): string
    {
        try {
            if (! is_array($payload)) {
                $payload = json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR);
            }
        } catch (JsonException) {
            return '';
        }

        return is_array($payload)
            ? (string) ($payload['data']['order_id'] ?? '')
            : '';
    }
}
