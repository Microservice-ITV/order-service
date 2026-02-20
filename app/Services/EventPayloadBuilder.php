<?php

namespace App\Services;

use App\Enums\EventTopic;
use Carbon\Carbon;

final class EventPayloadBuilder
{
    public static function build(EventTopic $topic, string $correlationId, array $data): array
    {
        return [
            'event'          => $topic->value,
            'version'        => 'v1',
            'timestamp'      => Carbon::now()->utc()->toIso8601String(),
            'producer'       => 'order-service',
            'correlation_id' => $correlationId,
            'data'           => $data,
        ];
    }
}
