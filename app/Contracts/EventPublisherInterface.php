<?php

namespace App\Contracts;

use App\Enums\EventTopic;

interface EventPublisherInterface
{
    public function publish(EventTopic $topic, array $payload): void;
}
