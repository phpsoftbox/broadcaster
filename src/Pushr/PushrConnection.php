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
        /** Соединение бэкенда с подписью публикатора: только ему разрешён `publish`. */
        public readonly bool $publisher = false,
    ) {
        $this->socket = $socket;
    }
}
