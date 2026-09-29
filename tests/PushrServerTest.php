<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use Closure;
use PhpSoftBox\Broadcaster\Pushr\PushrAppRegistry;
use PhpSoftBox\Broadcaster\Pushr\PushrChannelAuth;
use PhpSoftBox\Broadcaster\Pushr\PushrConnection;
use PhpSoftBox\Broadcaster\Pushr\PushrServer;
use PhpSoftBox\Broadcaster\Pushr\PushrSignature;
use PhpSoftBox\Broadcaster\Pushr\WebSocketFrame;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;
use function fclose;
use function fgets;
use function fread;
use function fwrite;
use function http_build_query;
use function is_array;
use function json_decode;
use function json_encode;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;
use function substr;
use function time;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

#[CoversClass(PushrServer::class)]
#[CoversClass(PushrConnection::class)]
#[CoversClass(PushrAppRegistry::class)]
#[CoversMethod(PushrServer::class, 'run')]
final class PushrServerTest extends TestCase
{
    /**
     * Same channel names must stay isolated across Pushr app_id boundaries.
     *
     * @see PushrServer::run()
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
        [$publisher, $publisherPeer] = $this->createClientPair('socket-publisher', 'tenant-a', publisher: true);

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
     * Проверим, что подписчик приватного канала не может публиковать в него, даже имея валидный auth подписки:
     * остальные подписчики не получают поддельное событие, отправитель получает ошибку.
     *
     * @see PushrServer::run()
     * @see PushrChannelAuth::token()
     */
    #[Test]
    public function publishFromSubscriberConnectionIsRejected(): void
    {
        $server = new PushrServer(new PushrAppRegistry(['tenant-a' => 'secret-a']));

        [$victim, $victimPeer]     = $this->createClientPair('socket-victim', 'tenant-a');
        [$attacker, $attackerPeer] = $this->createClientPair('socket-attacker', 'tenant-a');

        try {
            $this->setClients($server, [$victim, $attacker]);
            $this->subscribe($server, $victim, 'public.news');

            // Auth подписки, который бэкенд честно выдал атакующему для его сокета.
            $auth = PushrChannelAuth::token('tenant-a', 'secret-a', 'socket-attacker', 'private.tenant.1');
            $this->handleFrame($server, $attacker, [
                'type'    => 'publish',
                'channel' => 'private.tenant.1',
                'event'   => 'fake.notification',
                'auth'    => $auth,
            ]);
            $this->handleFrame($server, $attacker, [
                'type'    => 'publish',
                'channel' => 'public.news',
                'event'   => 'fake.news',
            ]);

            $victimMessages = $this->readMessages($victimPeer);
            self::assertFalse($this->hasEvent($victimMessages, 'fake.notification'));
            self::assertFalse($this->hasEvent($victimMessages, 'fake.news'));
            self::assertSame(
                [
                    ['type' => 'error', 'message' => 'Publish is not allowed for this connection'],
                    ['type' => 'error', 'message' => 'Publish is not allowed for this connection'],
                ],
                $this->readMessages($attackerPeer),
            );
        } finally {
            fclose($victim->socket);
            fclose($victimPeer);
            fclose($attacker->socket);
            fclose($attackerPeer);
        }
    }

    /**
     * Проверим, что handshake с подписью публикатора и role=publisher создаёт соединение с правом публикации.
     *
     * @see PushrServer::run()
     * @see PushrSignature::generatePublisher()
     */
    #[Test]
    public function handshakeWithPublisherSignatureCreatesPublisherConnection(): void
    {
        $timestamp = time();
        $signature = PushrSignature::generatePublisher('tenant-a', 'secret-a', $timestamp);

        [$connection, $response] = $this->handshake('tenant-a', $timestamp, $signature, 'publisher');

        self::assertStringStartsWith('HTTP/1.1 101', $response);
        self::assertInstanceOf(PushrConnection::class, $connection);
        self::assertTrue($connection->publisher);
    }

    /**
     * Проверим, что обычная подпись браузера не даёт роль публикатора: handshake отклоняется.
     *
     * @see PushrServer::run()
     * @see PushrSignature::generate()
     */
    #[Test]
    public function handshakeRejectsBrowserSignatureWithPublisherRole(): void
    {
        $timestamp = time();
        $signature = PushrSignature::generate('tenant-a', 'secret-a', $timestamp);

        [$connection, $response] = $this->handshake('tenant-a', $timestamp, $signature, 'publisher');

        self::assertStringStartsWith('HTTP/1.1 401 Invalid signature', $response);
        self::assertNull($connection);
    }

    /**
     * Проверим, что обычная подпись браузера создаёт соединение без права публикации.
     *
     * @see PushrServer::run()
     * @see PushrSignature::generate()
     */
    #[Test]
    public function handshakeWithBrowserSignatureCreatesSubscriberConnection(): void
    {
        $timestamp = time();
        $signature = PushrSignature::generate('tenant-a', 'secret-a', $timestamp);

        [$connection, $response] = $this->handshake('tenant-a', $timestamp, $signature);

        self::assertStringStartsWith('HTTP/1.1 101', $response);
        self::assertInstanceOf(PushrConnection::class, $connection);
        self::assertFalse($connection->publisher);
    }

    /**
     * Проверим, что кадр с некорректной 64-битной длиной закрывает соединение, а не зацикливает сервер
     * (раньше frameLength мог стать нулевым, и цикл разбора буфера не завершался).
     *
     * @see PushrServer::run()
     * @see WebSocketFrame::decode()
     */
    #[Test]
    public function frameWithInvalidLengthClosesConnection(): void
    {
        $server = new PushrServer(new PushrAppRegistry(['tenant-a' => 'secret-a']));

        [$client, $peer] = $this->createClientPair('socket-a', 'tenant-a');

        try {
            $this->setClients($server, [$client]);

            // Длина 0xFFFFFFFFFFFFFFF6 = -10 после unpack('J'): offset 10 + длина = 0.
            fwrite($peer, "\x81\x7F\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xF6");
            usleep(10000);

            $clients = (Closure::bind(
                static function (PushrServer $server, PushrConnection $client): array {
                    $server->readClient($client);

                    return $server->clients;
                },
                null,
                PushrServer::class,
            ))($server, $client);

            self::assertSame([], $clients);
        } finally {
            fclose($peer);
        }
    }

    /**
     * Выполняет handshake через настоящий TCP-сокет и возвращает созданное сервером соединение и ответ.
     *
     * @return array{0:?PushrConnection, 1:string}
     */
    private function handshake(string $appId, int $timestamp, string $signature, ?string $role = null): array
    {
        $server   = new PushrServer(new PushrAppRegistry([$appId => 'secret-a']));
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertNotFalse($listener, $errorMessage);

        $client = stream_socket_client('tcp://' . stream_socket_get_name($listener, false));
        self::assertNotFalse($client);

        try {
            $query = http_build_query(array_filter([
                'app_id'    => $appId,
                'timestamp' => $timestamp,
                'signature' => $signature,
                'role'      => $role,
            ], static fn (mixed $value): bool => $value !== null));
            fwrite(
                $client,
                "GET /?{$query} HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n",
            );

            $connection = (Closure::bind(
                static function (PushrServer $server, mixed $listener): ?PushrConnection {
                    // Сервер принимает соединение без чтения и дочитывает handshake, когда сокет готов (как в run()).
                    $server->accept($listener);
                    $socket = array_values($server->handshakes)[0]['socket'];
                    $read   = [$socket];
                    $write  = null;
                    $except = null;
                    stream_select($read, $write, $except, 1);
                    $server->readHandshake($socket);

                    return array_values($server->clients)[0] ?? null;
                },
                null,
                PushrServer::class,
            ))($server, $listener);

            $response = (string) fgets($client);
            if ($connection !== null) {
                fclose($connection->socket);
            }

            return [$connection, $response];
        } finally {
            fclose($client);
            fclose($listener);
        }
    }

    /**
     * @return array{0:PushrConnection, 1:resource}
     */
    private function createClientPair(string $socketId, string $appId, bool $publisher = false): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            self::markTestSkipped('stream_socket_pair is not available.');
        }

        stream_set_blocking($pair[1], false);

        return [new PushrConnection($pair[0], $socketId, $appId, $publisher), $pair[1]];
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
