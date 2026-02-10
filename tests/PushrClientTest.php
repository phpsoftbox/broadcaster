<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use PhpSoftBox\Broadcaster\Pushr\PushrClient;
use PhpSoftBox\Broadcaster\Pushr\PushrPublisherOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function fclose;
use function file_exists;
use function microtime;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function usleep;

use const PHP_BINARY;

#[CoversClass(PushrClient::class)]
#[CoversMethod(PushrClient::class, 'connect')]
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
}
