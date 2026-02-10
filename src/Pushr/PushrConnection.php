<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Pushr;

final class PushrConnection
{
    /** @var resource */
    public $socket;

    /** @var array<string, true> */
    public array $channels = [];

    public string $buffer = '';

    public function __construct(
        $socket,
        public readonly string $id,
        public readonly string $appId,
    ) {
        $this->socket = $socket;
    }
}
