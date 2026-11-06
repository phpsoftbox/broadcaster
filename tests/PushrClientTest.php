<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use Closure;
use PhpSoftBox\Broadcaster\Pushr\PushrClient;
use PhpSoftBox\Broadcaster\Pushr\PushrPublisherOptions;
use PhpSoftBox\Broadcaster\Pushr\WebSocketFrame;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function fclose;
use function file_exists;
use function fread;
use function fwrite;
use function microtime;
use function pack;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function stream_set_blocking;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function usleep;

use const PHP_BINARY;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

#[CoversClass(PushrClient::class)]
#[CoversMethod(PushrClient::class, 'connect')]
#[CoversMethod(PushrClient::class, 'receive')]
final class PushrClientTest extends TestCase
{
    /**
     * Проверяет, что недоступный endpoint завершается ошибкой в пределах заданного короткого connect timeout.
     *
     * @see PushrClient::connect()
     */
    #[Test]
    public function connectToUnavailableEndpointHonorsConfiguredTimeoutBoundary(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($server, $errorMessage);
        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);
        fclose($server);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        $client = new PushrClient(
            '127.0.0.1',
            $port,
            'app-1',
            'secret-1',
            options: new PushrPublisherOptions(connectTimeoutSeconds: 0.05),
        );
        $startedAt = microtime(true);

        try {
            $client->connect();
            self::fail('Connection failure was expected.');
        } catch (RuntimeException) {
            self::assertLessThan(0.5, microtime(true) - $startedAt);
        }
    }

    /**
     * Проверяет отдельный timeout WebSocket-handshake, если TCP-соединение установлено, но сервер не отвечает.
     *
     * @see PushrClient::connect()
     */
    #[Test]
    public function connectStopsWaitingWhenHandshakeTimeoutExpires(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($probe, $errorMessage);
        $address = stream_socket_get_name($probe, false);
        self::assertIsString($address);
        fclose($probe);
        $port      = (int) substr($address, strrpos($address, ':') + 1);
        $readyPath = sys_get_temp_dir() . '/pushr-handshake-' . uniqid('', true);
        $code      = <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:' . $argv[1]);
file_put_contents($argv[2], 'ready');
$connection = stream_socket_accept($server, 2);
if (is_resource($connection)) {
    fread($connection, 8192);
    usleep(500000);
    fclose($connection);
}
fclose($server);
PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $code, (string) $port, $readyPath],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        try {
            for ($attempt = 0; $attempt < 100 && !file_exists($readyPath); $attempt++) {
                usleep(5_000);
            }
            self::assertFileExists($readyPath);

            $client = new PushrClient(
                '127.0.0.1',
                $port,
                'app-1',
                'secret-1',
                options: new PushrPublisherOptions(handshakeTimeoutSeconds: 0.05),
            );
            $startedAt = microtime(true);

            try {
                $client->connect();
                self::fail('Handshake timeout was expected.');
            } catch (RuntimeException $exception) {
                self::assertSame('Pushr handshake timed out.', $exception->getMessage());
                self::assertLessThan(0.3, microtime(true) - $startedAt);
            }
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            if (file_exists($readyPath)) {
                unlink($readyPath);
            }
        }
    }

    /**
     * Проверим, что на ping сервера клиент отвечает pong с тем же payload и продолжает отдавать сообщения.
     *
     * @see PushrClient::receive()
     */
    #[Test]
    public function receiveAnswersServerPingWithPong(): void
    {
        [$client, $server] = $this->connectedClient();

        try {
            fwrite(
                $server,
                WebSocketFrame::encode('probe', false, WebSocketFrame::OPCODE_PING)
                . WebSocketFrame::encode('{"type":"event","event":"tick"}', false),
            );

            self::assertSame(['type' => 'event', 'event' => 'tick'], $client->receive(0.5));

            $pong = WebSocketFrame::decode((string) fread($server, 8192));
            self::assertNotNull($pong);
            self::assertSame(WebSocketFrame::OPCODE_PONG, $pong['opcode']);
            self::assertSame('probe', $pong['payload']);
        } finally {
            $client->close();
            fclose($server);
        }
    }

    /**
     * Проверим, что close-кадр сервера закрывает соединение клиента: ответный close отправлен, дальнейшая
     * публикация требует переподключения.
     *
     * @see PushrClient::receive()
     * @see PushrClient::publish()
     */
    #[Test]
    public function receiveTreatsServerCloseFrameAsClosedConnection(): void
    {
        [$client, $server] = $this->connectedClient();

        try {
            fwrite($server, WebSocketFrame::encode(pack('n', 1001), false, WebSocketFrame::OPCODE_CLOSE));

            self::assertNull($client->receive(0.5));

            $close = WebSocketFrame::decode((string) fread($server, 8192));
            self::assertNotNull($close);
            self::assertSame(WebSocketFrame::OPCODE_CLOSE, $close['opcode']);
            self::assertSame(pack('n', 1001), $close['payload']);

            $this->expectExceptionObject(new RuntimeException('Pushr client is not connected.'));
            $client->publish('news', 'message');
        } finally {
            fclose($server);
        }
    }

    /**
     * Проверим закрывающее рукопожатие: после публикаций клиент шлёт close 1000 и ждёт ответный close сервера —
     * к его ответу сервер прочитал все публикации, а сокет закрывается без непрочитанных данных (без RST).
     *
     * @see PushrClient::close()
     */
    #[Test]
    public function closePerformsWebSocketClosingHandshakeAfterPublications(): void
    {
        [$client, $server] = $this->connectedClient();

        try {
            // The greeting of the server is not read by a publisher; the answer to its close is already waiting.
            fwrite($server, WebSocketFrame::encode('{"type":"connection","socket_id":"1"}', false)
                . WebSocketFrame::encode(pack('n', 1000), false, WebSocketFrame::OPCODE_CLOSE));
            $client->publish('first', 'message');
            $client->publish('second', 'message');

            $started = microtime(true);
            $client->close();
            self::assertLessThan(0.5, microtime(true) - $started);

            $received = (string) fread($server, 8192);
            $opcodes  = [];
            $payloads = [];
            while (($frame = WebSocketFrame::decode($received)) !== null) {
                $received   = substr($received, $frame['frameLength']);
                $opcodes[]  = $frame['opcode'];
                $payloads[] = $frame['payload'];
            }
            self::assertSame([WebSocketFrame::OPCODE_TEXT, WebSocketFrame::OPCODE_TEXT, WebSocketFrame::OPCODE_CLOSE], $opcodes);
            self::assertStringContainsString('"channel":"second"', $payloads[1]);
            self::assertSame(pack('n', 1000), $payloads[2]);

            $this->expectExceptionObject(new RuntimeException('Pushr client is not connected.'));
            $client->publish('third', 'message');
        } finally {
            fclose($server);
        }
    }

    /**
     * Проверим, что сервер без ответного close не задерживает клиента дольше `closeTimeoutSeconds`.
     *
     * @see PushrClient::close()
     */
    #[Test]
    public function closeStopsWaitingForServerAfterCloseTimeout(): void
    {
        [$client, $server] = $this->connectedClient(new PushrPublisherOptions(closeTimeoutSeconds: 0.2));

        try {
            $started = microtime(true);
            $client->close();
            $elapsed = microtime(true) - $started;

            self::assertGreaterThanOrEqual(0.15, $elapsed);
            self::assertLessThan(0.8, $elapsed);
            self::assertNull($client->receive());
        } finally {
            fclose($server);
        }
    }

    /**
     * Клиент с уже установленным соединением поверх пары сокетов (handshake не нужен для разбора кадров).
     *
     * @return array{0:PushrClient, 1:resource}
     */
    private function connectedClient(?PushrPublisherOptions $options = null): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            self::markTestSkipped('stream_socket_pair is not available.');
        }

        stream_set_blocking($pair[0], false);
        $client = new PushrClient('127.0.0.1', 8080, 'app-1', 'secret-1', '/', $options ?? new PushrPublisherOptions());

        (Closure::bind(
            static function (PushrClient $client, mixed $socket): void {
                $client->socket = $socket;
            },
            null,
            PushrClient::class,
        ))($client, $pair[0]);

        return [$client, $pair[1]];
    }
}
