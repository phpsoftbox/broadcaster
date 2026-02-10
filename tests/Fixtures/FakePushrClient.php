<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests\Fixtures;

use PhpSoftBox\Broadcaster\Contracts\PushrClientInterface;
use RuntimeException;

use function array_shift;

final class FakePushrClient implements PushrClientInterface
{
    public int $connectCalls = 0;
    public int $closeCalls   = 0;
    public int $receiveCalls = 0;

    /** @var list<array{channel:string,event:string,data:mixed,auth:?string,channelData:mixed}> */
    public array $publications = [];

    /** @var list<array<string, mixed>|null> */
    public array $receivedMessages = [];

    public ?string $failOnChannel = null;

    public function connect(): void
    {
        $this->connectCalls++;
    }

    public function close(): void
    {
        $this->closeCalls++;
    }

    public function publish(
        string $channel,
        string $event,
        mixed $data = null,
        ?string $auth = null,
        mixed $channelData = null,
    ): void {
        if ($channel === $this->failOnChannel) {
            throw new RuntimeException('Test publication failure.');
        }

        $this->publications[] = [
            'channel'     => $channel,
            'event'       => $event,
            'data'        => $data,
            'auth'        => $auth,
            'channelData' => $channelData,
        ];
    }

    public function receive(float $timeoutSeconds = 0.0): ?array
    {
        $this->receiveCalls++;

        return array_shift($this->receivedMessages);
    }
}
