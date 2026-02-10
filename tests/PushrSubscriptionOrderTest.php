<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use PhpSoftBox\Broadcaster\Pushr\PushrAppRegistry;
use PhpSoftBox\Broadcaster\Pushr\PushrConnection;
use PhpSoftBox\Broadcaster\Pushr\PushrServer;
use PhpSoftBox\Broadcaster\Pushr\WebSocketFrame;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

use function fclose;
use function json_decode;
use function json_encode;
use function stream_get_contents;
use function stream_set_blocking;
use function stream_socket_pair;
use function substr;

use const JSON_THROW_ON_ERROR;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

#[CoversClass(PushrServer::class)]
#[CoversClass(WebSocketFrame::class)]
#[CoversMethod(PushrServer::class, 'handleFrame')]
final class PushrSubscriptionOrderTest extends TestCase
{
    /**
     * Проверяет порядок подтверждений и событий двух циклов канала: unsubscribed отделяет старую подписку от новой.
     *
     * @see PushrServer::run()
     * @see WebSocketFrame::decode()
     */
    #[Test]
    public function unsubscribeAcknowledgementSeparatesSubscriptionCycles(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            self::markTestSkipped('stream_socket_pair is unavailable.');
        }

        [$socket, $peer] = $pair;
        stream_set_blocking($peer, false);

        try {
            $server = new PushrServer(new PushrAppRegistry(['app' => 'secret']));
            $client = new PushrConnection($socket, 'socket-1', 'app');

            new ReflectionProperty(PushrServer::class, 'clients')->setValue($server, [(int) $socket => $client]);

            $handler = new ReflectionMethod(PushrServer::class, 'handleFrame');

            foreach ([
                            ['type' => 'subscribe', 'channel' => 'news'],
                            ['type' => 'publish', 'channel' => 'news', 'event' => 'old'],
                            ['type' => 'unsubscribe', 'channel' => 'news'],
                            ['type' => 'publish', 'channel' => 'news', 'event' => 'between'],
                            ['type' => 'subscribe', 'channel' => 'news'],
                            ['type' => 'publish', 'channel' => 'news', 'event' => 'new'],
                        ] as $command) {
                // run() передаёт декодированные команды в handleFrame последовательно на одном соединении.
                $handler->invoke($server, $client, 1, json_encode($command, JSON_THROW_ON_ERROR));
            }

            $buffer = stream_get_contents($peer);
            self::assertIsString($buffer);
            $messages = [];
            while ($buffer !== '') {
                $frame = WebSocketFrame::decode($buffer);
                self::assertNotNull($frame);
                $message    = json_decode($frame['payload'], true, flags: JSON_THROW_ON_ERROR);
                $messages[] = [$message['type'], $message['channel'], $message['event'] ?? null];
                $buffer     = substr($buffer, $frame['frameLength']);
            }

            self::assertSame([
                ['subscribed', 'news', null],
                ['event', 'news', 'old'],
                ['unsubscribed', 'news', null],
                ['subscribed', 'news', null],
                ['event', 'news', 'new'],
            ], $messages);
        } finally {
            fclose($socket);
            fclose($peer);
        }
    }
}
