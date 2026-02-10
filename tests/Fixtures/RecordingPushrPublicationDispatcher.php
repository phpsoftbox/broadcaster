<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests\Fixtures;

use PhpSoftBox\Broadcaster\Contracts\PushrPublicationDispatcherInterface;
use PhpSoftBox\Broadcaster\Pushr\PushrPublication;

final class RecordingPushrPublicationDispatcher implements PushrPublicationDispatcherInterface
{
    /** @var list<PushrPublication> */
    public array $publications = [];

    public function dispatch(PushrPublication $publication): void
    {
        $this->publications[] = $publication;
    }
}
