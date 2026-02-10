<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Channel;

use function ltrim;
use function str_starts_with;
use function trim;

final readonly class PrivateChannel extends AbstractChannel
{
    public function __construct(
        private string $name,
    ) {
    }

    public function name(): string
    {
        $name = trim($this->name);

        if (str_starts_with($name, 'private.')) {
            return $name;
        }

        return 'private.' . ltrim($name, '.');
    }
}
