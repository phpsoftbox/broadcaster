<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Http;

final readonly class BroadcastResponse
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private array $payload,
        private int $status = 200,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function status(): int
    {
        return $this->status;
    }
}
