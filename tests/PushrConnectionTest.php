<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use PhpSoftBox\Broadcaster\Pushr\PushrConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fread;
use function is_resource;
use function str_repeat;
use function stream_set_blocking;
use function stream_socket_pair;
use function strlen;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

#[CoversClass(PushrConnection::class)]
#[CoversMethod(PushrConnection::class, 'queue')]
#[CoversMethod(PushrConnection::class, 'flush')]
#[CoversMethod(PushrConnection::class, 'hasPendingOutput')]
final class PushrConnectionTest extends TestCase
{
    /** @var list<resource> */
    private array $sockets = [];

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }

        $this->sockets = [];
    }

    /**
     * Проверим, что при заполненном буфере ОС остаток данных остаётся в очереди, а не теряется и не считается ошибкой.
     *
     * @see PushrConnection::queue()
     * @see PushrConnection::hasPendingOutput()
     */
    #[Test]
    public function queueKeepsRemainderWhenSocketBufferIsFull(): void
    {
        [$connection] = $this->connection();
        $data         = str_repeat('a', 4 * 1024 * 1024);

        self::assertTrue($connection->queue($data));
        self::assertTrue($connection->hasPendingOutput());
        self::assertLessThan(strlen($data), strlen($connection->outgoing));
    }

    /**
     * Проверим, что после чтения на стороне клиента flush() дописывает очередь и данные доходят целиком.
     *
     * @see PushrConnection::flush()
     */
    #[Test]
    public function flushDeliversRemainderAfterPeerReads(): void
    {
        [$connection, $peer] = $this->connection();
        $data                = str_repeat('0123456789abcdef', 65536);
        $connection->queue($data);

        $received = '';
        for ($i = 0; $i < 1000 && strlen($received) < strlen($data); $i++) {
            $received .= (string) fread($peer, 1_048_576);
            self::assertTrue($connection->flush());
        }

        self::assertSame($data, $received);
        self::assertFalse($connection->hasPendingOutput());
    }

    /**
     * Проверим, что запись в сокет, другая сторона которого закрыта, сообщается как ошибка.
     *
     * @see PushrConnection::queue()
     */
    #[Test]
    public function queueReportsWriteErrorWhenPeerIsGone(): void
    {
        [$connection, $peer] = $this->connection();
        fclose($peer);

        self::assertFalse($connection->queue('ping'));
    }

    /**
     * @return array{0:PushrConnection, 1:resource}
     */
    private function connection(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            self::markTestSkipped('stream_socket_pair is not available.');
        }

        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);
        $this->sockets[] = $pair[0];
        $this->sockets[] = $pair[1];

        return [new PushrConnection($pair[0], 'socket-a', 'tenant-a'), $pair[1]];
    }
}
