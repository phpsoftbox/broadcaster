<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Pushr;

final readonly class PushrAppRegistry
{
    /**
     * @param array<string, string> $apps app_id => secret
     */
    public function __construct(
        private array $apps,
    ) {
    }

    public function secret(string $appId): ?string
    {
        return $this->apps[$appId] ?? null;
    }
}
