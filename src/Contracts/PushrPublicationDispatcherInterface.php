<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Contracts;

use PhpSoftBox\Broadcaster\Pushr\PushrPublication;

interface PushrPublicationDispatcherInterface
{
    public function dispatch(PushrPublication $publication): void;
}
