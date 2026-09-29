<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Contracts;

interface PushrClientInterface
{
    public function connect(): void;

    public function close(): void;

    /**
     * Клиент подключается с подписью публикатора, поэтому auth каналов для публикации не нужен.
     */
    public function publish(string $channel, string $event, mixed $data = null): void;

    /** @return array<string, mixed>|null */
    public function receive(float $timeoutSeconds = 0.0): ?array;
}
