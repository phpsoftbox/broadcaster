<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Contracts;

use Stringable;

interface PushrPublisherInterface
{
    public function publish(string|Stringable $channel, string $event, mixed $data = null): void;

    /**
     * @param iterable<string|Stringable> $channels
     */
    public function publishMany(iterable $channels, string $event, mixed $data = null): void;
}
