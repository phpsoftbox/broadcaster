<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Contracts;

use PhpSoftBox\Broadcaster\Pushr\PushrAppRegistry;

interface PushrRegistryBuilderInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function build(array $options = []): PushrAppRegistry;
}
