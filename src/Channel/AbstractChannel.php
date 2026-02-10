<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Channel;

use Stringable;

abstract readonly class AbstractChannel implements Stringable
{
    final public function __toString(): string
    {
        return $this->name();
    }

    abstract public function name(): string;
}
