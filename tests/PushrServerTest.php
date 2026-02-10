<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use Closure;
use PhpSoftBox\Broadcaster\Pushr\PushrAppRegistry;
use PhpSoftBox\Broadcaster\Pushr\PushrConnection;
use PhpSoftBox\Broadcaster\Pushr\PushrServer;
use PhpSoftBox\Broadcaster\Pushr\WebSocketFrame;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fread;
use function is_array;
use function json_decode;
use function json_encode;
use function stream_set_blocking;
use function stream_socket_pair;
use function substr;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

#[CoversClass(PushrServer::class)]
#[CoversClass(PushrConnection::class)]
#[CoversClass(PushrAppRegistry::class)]
final class PushrServerTest extends TestCase
{
    /**
     * Same channel names must stay isolated across Pushr app_id boundaries.
     */
    #[Test]
    public function publishDoesNotCrossAppBoundaryWhenChannelNamesMatch(): void
    {
        $server = new PushrServer(new PushrAppRegistry([
            'tenant-a' => 'secret-a',
            'tenant-b' => 'secret-b',
        ]));

        [$clientA, $peerA]           = $this->createClientPair('socket-a', 'tenant-a');
        [$clientB, $peerB]           = $this->createClientPair('socket-b', 'tenant-b');
        [$publisher, $publisherPeer] = $this->createClientPair('socket-publisher', 'tenant-a');

        try {
            $this->setClients($server, [$clientA, $clientB]);

            $this->subscribe($server, $clientA, 'public.shipments');
            $this->subscribe($server, $clientB, 'public.shipments');

            $this->handleFrame($server, $publisher, [
                'type'    => 'publish',
                'channel' => 'public.shipments',
                'event'   => 'shipment.updated',
                'data'    => ['id' => 10],
            ]);

            $messagesA = $this->readMessages($peerA);
            $messagesB = $this->readMessages($peerB);

            self::assertTrue($this->hasEvent($messagesA, 'shipment.updated'));
            self::assertFalse($this->hasEvent($messagesB, 'shipment.updated'));
        } finally {
            fclose($clientA->socket);
            fclose($peerA);
            fclose($clientB->socket);
            fclose($peerB);
            fclose($publisher->socket);
            fclose($publisherPeer);
        }
    }

    /**
     * @return array{0:PushrConnection, 1:resource}
     */
    private function createClientPair(string $socketId, string $appId): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            self::markTestSkipped('stream_socket_pair is not available.');
        }

        stream_set_blocking($pair[1], false);

        return [new PushrConnection($pair[0], $socketId, $appId), $pair[1]];
    }

    /**
     * @param list<PushrConnection> $clients
     */
    private function setClients(PushrServer $server, array $clients): void
    {
        (Closure::bind(
            static function (PushrServer $server, array $clients): void {
                foreach ($clients as $client) {
                    $server->clients[(int) $client->socket] = $client;
                }
            },
            null,
            PushrServer::class,
        ))($server, $clients);
    }

    private function subscribe(PushrServer $server, PushrConnection $client, string $channel): void
    {
        (Closure::bind(
            static fn (PushrServer $server, PushrConnection $client, string $channel): mixed => $server->subscribe($client, $channel),
            null,
            PushrServer::class,
        ))($server, $client, $channel);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleFrame(PushrServer $server, PushrConnection $client, array $message): void
    {
        (Closure::bind(
            static fn (PushrServer $server, PushrConnection $client, string $payload): mixed => $server->handleFrame($client, 1, $payload),
            null,
            PushrServer::class,
        ))($server, $client, json_encode($message, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readMessages(mixed $socket): array
    {
        usleep(10000);

        $buffer = '';
        while (true) {
            $chunk = fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
        }

        $messages = [];
        while ($buffer !== '') {
            $frame = WebSocketFrame::decode($buffer);
            if ($frame === null) {
                break;
            }

            $buffer = substr($buffer, $frame['frameLength']);
            if ($frame['opcode'] !== 1) {
                continue;
            }

            $decoded = json_decode($frame['payload'], true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $messages[] = $decoded;
            }
        }

        return $messages;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function hasEvent(array $messages, string $event): bool
    {
        foreach ($messages as $message) {
            if (($message['type'] ?? null) === 'event' && ($message['event'] ?? null) === $event) {
                return true;
            }
        }

        return false;
    }
}
