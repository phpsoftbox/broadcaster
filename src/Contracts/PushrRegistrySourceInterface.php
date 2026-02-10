<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Contracts;

interface PushrRegistrySourceInterface
{
    /**
     * @param array<string, mixed> $options
     * @return array<string, string> app_id => secret
     */
    public function apps(array $options = []): array;
}
