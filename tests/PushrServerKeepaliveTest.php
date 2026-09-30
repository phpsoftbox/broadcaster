<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use Closure;
use InvalidArgumentException;
use PhpSoftBox\Broadcaster\Pushr\PushrAppRegistry;
use PhpSoftBox\Broadcaster\Pushr\PushrConnection;
use PhpSoftBox\Broadcaster\Pushr\PushrServer;
use PhpSoftBox\Broadcaster\Pushr\WebSocketFrame;
use PhpSoftBox\Broadcaster\Tests\Fixtures\ManualClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fread;
use function fwrite;
use function is_resource;
use function json_encode;
use function pack;
use function str_repeat;
use function stream_select;
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
#[CoversMethod(PushrServer::class, '__construct')]
#[CoversMethod(PushrServer::class, 'run')]
final class PushrServerKeepaliveTest extends TestCase
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
     * Проверим, что на ping клиента (opcode 9) сервер отвечает pong (opcode 10) с тем же payload.
     *
     * @see PushrServer::run()
     */
    #[Test]
    public function pingFrameIsAnsweredWithPongCarryingSamePayload(): void
    {
        $clock = new ManualClock();

        $server          = $this->server($clock);
        [$client, $peer] = $this->connect($server, $clock);

        // Клиентские кадры маскируются, как требует RFC 6455.
        $this->receive($server, $client, $peer, WebSocketFrame::encode('probe-1', true, WebSocketFrame::OPCODE_PING));

        self::assertSame([[WebSocketFrame::OPCODE_PONG, 'probe-1']], $this->readFrames($peer));
    }

    /**
     * Проверим, что прикладной ping `{"type":"ping"}` получает ответ `{"type":"pong"}`.
     *
     * @see PushrServer::run()
     */
    #[Test]
    public function applicationPingIsAnsweredWithApplicationPong(): void
    {
        $clock = new ManualClock();

        $server          = $this->server($clock);
        [$client, $peer] = $this->connect($server, $clock);

        $this->receive($server, $client, $peer, $this->text(['type' => 'ping']));

        self::assertSame([[WebSocketFrame::OPCODE_TEXT, '{"type":"pong"}']], $this->readFrames($peer));
    }

    /**
     * Проверим, что ping отправляется только после pingInterval секунд простоя и не чаще одного за интервал.
     *
     * @see PushrServer::run()
     */
    #[Test]
    public function keepaliveSendsSinglePingAfterPingInterval(): void
    {
        $clock = new ManualClock();

        $server          = $this->server($clock);
        [$client, $peer] = $this->connect($server, $clock);

        // До истечения интервала ping нет.
        $clock->advance(24);
        $this->keepalive($server);
        self::assertSame([], $this->readFrames($peer));

        // Интервал истёк: ровно один ping, повторная проверка в ту же секунду второй не шлёт.
        $clock->advance(1);
        $this->keepalive($server);
        $this->keepalive($server);
        self::assertSame([[WebSocketFrame::OPCODE_PING, '']], $this->readFrames($peer));
        self::assertFalse($client->closed);
    }

    /**
     * Проверим, что соединение без входящих кадров idleTimeout секунд закрывается кадром 1001, а его каналы
     * освобождаются.
     *
     * @see PushrServer::run()
     */
    #[Test]
    public function keepaliveClosesIdleConnectionAndReleasesChannels(): void
    {
        $clock = new ManualClock();

        $server          = $this->server($clock);
        [$client, $peer] = $this->connect($server, $clock);
        $this->receive($server, $client, $peer, $this->text(['type' => 'subscribe', 'channel' => 'public.news']));
        $this->readFrames($peer);

        // Клиент не отвечает на ping: через idleTimeout соединение закрывается.
        $clock->advance(60);
        $this->keepalive($server);

        self::assertSame([[WebSocketFrame::OPCODE_CLOSE, pack('n', 1001)]], $this->readFrames($peer));
        self::assertTrue($client->closed);
        self::assertSame([], $this->state($server, 'clients'));
        self::assertSame([], $this->state($server, 'channels'));
    }

    /**
     * Проверим, что любой входящий кадр (здесь pong) сбрасывает таймер простоя.
     *
     * @see PushrServer::run()
     */
    #[Test]
    public function incomingFrameResetsIdleTimer(): void
    {
        $clock = new ManualClock();

        $server          = $this->server($clock);
        [$client, $peer] = $this->connect($server, $clock);

        $clock->advance(50);
        $this->receive($server, $client, $peer, WebSocketFrame::encode('', true, WebSocketFrame::OPCODE_PONG));

        // С handshake прошло 100 секунд, с последнего кадра — 50: соединение живо, ему уходит только ping.
        $clock->advance(50);
        $this->keepalive($server);

        self::assertFalse($client->closed);
        self::assertSame([[WebSocketFrame::OPCODE_PING, '']], $this->readFrames($peer));
    }

    /**
     * Проверим, что публикующее соединение, присылающее кадры чаще pingInterval, не получает ping и не закрывается.
     *
     * @see PushrServer::run()
     */
    #[Test]
    public function activePublisherConnectionIsNotAffected(): void
    {
        $clock = new ManualClock();

        $server          = $this->server($clock);
        [$client, $peer] = $this->connect($server, $clock, publisher: true);

        for ($i = 0; $i < 10; $i++) {
            $clock->advance(20);
            $this->receive($server, $client, $peer, $this->text([
                'type'    => 'publish',
                'channel' => 'public.news',
                'event'   => 'tick',
            ]));
            $this->keepalive($server);
        }

        self::assertFalse($client->closed);
        self::assertSame([], $this->readFrames($peer));
    }

    /**
     * Проверим, что idleTimeout = 0 сохраняет прежнее поведение: молчащее соединение не закрывается.
     *
     * @see PushrServer::run()
     */
    #[Test]
    public function zeroIdleTimeoutKeepsSilentConnection(): void
    {
        $clock = new ManualClock();

        $server          = $this->server($clock, idleTimeout: 0);
        [$client, $peer] = $this->connect($server, $clock);

        $clock->advance(3600);
        $this->keepalive($server);

        self::assertFalse($client->closed);
        self::assertSame([[WebSocketFrame::OPCODE_PING, '']], $this->readFrames($peer));
    }

    /**
     * Проверим, что ошибка записи ping (клиент исчез) сразу закрывает соединение и освобождает его каналы.
     *
     * @see PushrServer::run()
     */
    #[Test]
    public function pingWriteErrorClosesConnection(): void
    {
        $clock = new ManualClock();

        $server          = $this->server($clock);
        [$client, $peer] = $this->connect($server, $clock);
        $this->receive($server, $client, $peer, $this->text(['type' => 'subscribe', 'channel' => 'public.news']));
        fclose($peer);

        $clock->advance(25);
        $this->keepalive($server);

        self::assertTrue($client->closed);
        self::assertSame([], $this->state($server, 'clients'));
        self::assertSame([], $this->state($server, 'channels'));
    }

    /**
     * Проверим, что клиент, который не читает данные, отключается при переполнении очереди исходящих байт.
     *
     * @see PushrServer::run()
     */
    #[Test]
    public function outgoingQueueOverflowClosesConnection(): void
    {
        $clock = new ManualClock();

        $server          = $this->server($clock, maxOutgoingBytes: 65536);
        [$client, $peer] = $this->connect($server, $clock);
        $this->receive($server, $client, $peer, $this->text(['type' => 'subscribe', 'channel' => 'public.news']));
        [$publisher, $publisherPeer] = $this->connect($server, $clock, publisher: true);

        // Подписчик ничего не читает: буфер ОС заполняется, очередь растёт до лимита.
        for ($i = 0; $i < 64 && !$client->closed; $i++) {
            $this->receive($server, $publisher, $publisherPeer, $this->text([
                'type'    => 'publish',
                'channel' => 'public.news',
                'event'   => 'bulk',
                'data'    => str_repeat('x', 32768),
            ]));
        }

        self::assertTrue($client->closed);
        self::assertFalse($publisher->closed);
        self::assertSame([], $this->state($server, 'channels'));
    }

    /**
     * Проверим, что сообщение больше буфера сокета доходит целиком: остаток дописывается при готовности к записи.
     *
     * @see PushrServer::run()
     */
    #[Test]
    public function largeMessageIsDeliveredIntactThroughOutgoingQueue(): void
    {
        $clock = new ManualClock();

        $server          = $this->server($clock);
        [$client, $peer] = $this->connect($server, $clock);
        $this->receive($server, $client, $peer, $this->text(['type' => 'subscribe', 'channel' => 'public.news']));
        $this->readFrames($peer);
        [$publisher, $publisherPeer] = $this->connect($server, $clock, publisher: true);

        $data = str_repeat('0123456789', 90_000);
        $this->receive($server, $publisher, $publisherPeer, $this->text([
            'type'    => 'publish',
            'channel' => 'public.news',
            'event'   => 'big',
            'data'    => $data,
        ]));
        self::assertTrue($client->hasPendingOutput());

        // Клиент читает, сервер дописывает очередь (как по готовности к записи в run()).
        $buffer = '';
        for ($i = 0; $i < 1000 && ($client->hasPendingOutput() || $buffer === ''); $i++) {
            $buffer .= (string) fread($peer, 1_048_576);
            (Closure::bind(
                static fn (PushrServer $server, PushrConnection $client): mixed => $server->flush($client),
                null,
                PushrServer::class,
            ))($server, $client);
        }
        for ($i = 0; $i < 100 && WebSocketFrame::decode($buffer) === null; $i++) {
            $buffer .= (string) fread($peer, 1_048_576);
        }

        $frame = WebSocketFrame::decode($buffer);
        self::assertNotNull($frame);
        self::assertSame(
            json_encode(
                ['type' => 'event', 'channel' => 'public.news', 'event' => 'big', 'data' => $data],
                JSON_THROW_ON_ERROR,
            ),
            $frame['payload'],
        );
        self::assertFalse($client->closed);
    }

    /**
     * Проверим, что idleTimeout, не превышающий pingInterval, отклоняется.
     *
     * @see PushrServer::__construct()
     */
    #[Test]
    public function constructorRejectsIdleTimeoutNotGreaterThanPingInterval(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PushrServer(new PushrAppRegistry(['tenant-a' => 'secret-a']), pingInterval: 30, idleTimeout: 30);
    }

    private function server(ManualClock $clock, int $idleTimeout = 60, int $maxOutgoingBytes = 4194304): PushrServer
    {
        return new PushrServer(
            new PushrAppRegistry(['tenant-a' => 'secret-a']),
            pingInterval: 25,
            idleTimeout: $idleTimeout,
            maxOutgoingBytes: $maxOutgoingBytes,
            clock: $clock,
        );
    }

    /**
     * Регистрирует на сервере соединение поверх пары сокетов (как после handshake в момент времени часов).
     *
     * @return array{0:PushrConnection, 1:resource}
     */
    private function connect(PushrServer $server, ManualClock $clock, bool $publisher = false): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            self::markTestSkipped('stream_socket_pair is not available.');
        }

        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);
        $this->sockets[] = $pair[0];
        $this->sockets[] = $pair[1];

        $client = new PushrConnection($pair[0], 'socket-' . (int) $pair[0], 'tenant-a', $publisher, $clock->timestamp());

        (Closure::bind(
            static function (PushrServer $server, PushrConnection $client): void {
                $server->clients[(int) $client->socket] = $client;
            },
            null,
            PushrServer::class,
        ))($server, $client);

        return [$client, $pair[1]];
    }

    /**
     * Клиент пишет кадр в сокет, сервер читает его так же, как по готовности сокета в run().
     *
     * @param resource $peer
     */
    private function receive(PushrServer $server, PushrConnection $client, $peer, string $frame): void
    {
        $readClient = Closure::bind(
            static fn (PushrServer $server, PushrConnection $client): mixed => $server->readClient($client),
            null,
            PushrServer::class,
        );

        while ($frame !== '') {
            $written = fwrite($peer, $frame);
            $frame   = substr($frame, (int) $written);

            // Сервер читает, пока в сокете есть данные (как по готовности сокета в run()).
            while (!$client->closed && $this->readable($client->socket)) {
                $readClient($server, $client);
            }
        }
    }

    /**
     * @param resource $socket
     */
    private function readable($socket): bool
    {
        $read   = [$socket];
        $write  = null;
        $except = null;

        return stream_select($read, $write, $except, 0, 10000) > 0;
    }

    private function keepalive(PushrServer $server): void
    {
        (Closure::bind(
            static fn (PushrServer $server): mixed => $server->keepalive(),
            null,
            PushrServer::class,
        ))($server);
    }

    private function state(PushrServer $server, string $property): mixed
    {
        return (Closure::bind(
            static fn (PushrServer $server): mixed => $server->{$property},
            null,
            PushrServer::class,
        ))($server);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function text(array $message): string
    {
        return WebSocketFrame::encode(json_encode($message, JSON_THROW_ON_ERROR), true);
    }

    /**
     * @param resource $peer
     *
     * @return list<array{0:int, 1:string}> opcode и payload полученных кадров
     */
    private function readFrames($peer): array
    {
        usleep(1000);

        $buffer = '';
        while (true) {
            $chunk = fread($peer, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
        }

        $frames = [];
        while (($frame = WebSocketFrame::decode($buffer)) !== null) {
            $buffer   = substr($buffer, $frame['frameLength']);
            $frames[] = [$frame['opcode'], $frame['payload']];
        }

        return $frames;
    }
}
