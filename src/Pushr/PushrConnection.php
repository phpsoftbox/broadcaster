<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Pushr;

use function fwrite;
use function substr;

final class PushrConnection
{
    /** @var resource */
    public $socket;

    /** @var array<string, true> */
    public array $channels = [];

    /** Входящие байты, ещё не разобранные в кадры. */
    public string $buffer = '';

    /** Исходящие байты, которые сокет ещё не принял: дописываются, когда select отмечает его готовым к записи. */
    public string $outgoing = '';

    /** Unix-время последнего входящего кадра любого типа (при создании — время завершения handshake). */
    public int $lastSeenAt;

    /** Unix-время последнего ping от сервера, 0 — ping не отправлялся. */
    public int $lastPingAt = 0;

    public bool $closed = false;

    public function __construct(
        $socket,
        public readonly string $id,
        public readonly string $appId,
        /** Соединение бэкенда с подписью публикатора: только ему разрешён `publish`. */
        public readonly bool $publisher = false,
        int $lastSeenAt = 0,
    ) {
        $this->socket     = $socket;
        $this->lastSeenAt = $lastSeenAt;
    }

    /**
     * Дописывает байты в очередь и сразу пишет в сокет столько, сколько он примет.
     *
     * @return bool false — ошибка записи (соединение нужно закрыть)
     */
    public function queue(string $data): bool
    {
        $this->outgoing .= $data;

        return $this->flush();
    }

    /**
     * Пишет накопленную очередь, пока сокет принимает данные. Ноль записанных байт означает заполненный буфер ОС
     * и не является ошибкой: остаток допишется при следующей готовности сокета к записи.
     *
     * @return bool false — ошибка записи (соединение нужно закрыть)
     */
    public function flush(): bool
    {
        if ($this->closed) {
            return false;
        }

        while ($this->outgoing !== '') {
            $written = @fwrite($this->socket, $this->outgoing);
            if ($written === false) {
                return false;
            }

            if ($written === 0) {
                return true;
            }

            $this->outgoing = substr($this->outgoing, $written);
        }

        return true;
    }

    public function hasPendingOutput(): bool
    {
        return $this->outgoing !== '';
    }
}
