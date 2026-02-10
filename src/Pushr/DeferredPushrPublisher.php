<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Pushr;

use PhpSoftBox\Broadcaster\Contracts\PushrPublicationDispatcherInterface;
use PhpSoftBox\Broadcaster\Contracts\PushrPublisherInterface;
use Stringable;

final readonly class DeferredPushrPublisher implements PushrPublisherInterface
{
    public function __construct(
        private PushrPublicationDispatcherInterface $dispatcher,
    ) {
    }

    public function publish(string|Stringable $channel, string $event, mixed $data = null): void
    {
        $this->publishMany([$channel], $event, $data);
    }

    public function publishMany(iterable $channels, string $event, mixed $data = null): void
    {
        $this->dispatcher->dispatch(new PushrPublication($channels, $event, $data));
    }
}
