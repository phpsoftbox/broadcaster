<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Pushr;

use PhpSoftBox\Broadcaster\Contracts\PushrClientInterface;
use RuntimeException;
use Throwable;

use function base64_encode;
use function fclose;
use function fread;
use function fwrite;
use function http_build_query;
use function is_array;
use function json_decode;
use function json_encode;
use function microtime;
use function pack;
use function preg_match;
use function random_bytes;
use function sprintf;
use function stream_get_line;
use function stream_get_meta_data;
use function stream_select;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_socket_client;
use function strlen;
use function substr;
use function time;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class PushrClient implements PushrClientInterface
{
    /** @var resource|null */
    private $socket = null;

    private string $buffer = '';
    private readonly PushrPublisherOptions $options;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $appId,
        private readonly string $secret,
        private readonly string $path = '/',
        ?PushrPublisherOptions $options = null,
    ) {
        $this->options = $options ?? new PushrPublisherOptions();
    }

    public function connect(): void
    {
        $this->close();

        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $errstr,
            $this->options->connectTimeoutSeconds,
        );
        if ($socket === false) {
            $reason = $errstr !== '' ? $errstr : ('socket error #' . $errno);

            throw new RuntimeException('Unable to connect to Pushr server: ' . $reason);
        }

        try {
            $timestamp = time();
            $signature = PushrSignature::generatePublisher($this->appId, $this->secret, $timestamp);
            $query     = http_build_query([
                'app_id'    => $this->appId,
                'timestamp' => $timestamp,
                'signature' => $signature,
                'role'      => 'publisher',
            ]);

            $key     = base64_encode(random_bytes(16));
            $request = sprintf(
                "GET %s?%s HTTP/1.1\r\nHost: %s:%d\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: %s\r\nSec-WebSocket-Version: 13\r\n\r\n",
                $this->path,
                $query,
                $this->host,
                $this->port,
                $key,
            );

            stream_set_blocking($socket, false);
            $this->writeNonBlocking($socket, $request);
            stream_set_blocking($socket, true);
            $handshakeDeadline = microtime(true) + $this->options->handshakeTimeoutSeconds;

            $statusLine = $this->readHandshakeLine($socket, $handshakeDeadline);

            if (preg_match('/^HTTP\/\d(?:\.\d)? 101(?: |$)/', $statusLine) !== 1) {
                throw new RuntimeException('Pushr handshake failed: ' . $statusLine);
            }

            while (true) {
                $line = $this->readHandshakeLine($socket, $handshakeDeadline);

                if ($line === '') {
                    break;
                }
            }

            stream_set_blocking($socket, false);
            $this->socket = $socket;
        } catch (Throwable $exception) {
            fclose($socket);

            throw $exception;
        }
    }

    /**
     * Закрывающее рукопожатие WebSocket: close-кадр и ожидание ответного close сервера (не дольше
     * `closeTimeoutSeconds`). Сервер разбирает кадры по порядку, поэтому к его ответу все отправленные публикации
     * уже обработаны. Без рукопожатия сокет закрывался с непрочитанным приветствием сервера: ядро отвечало RST, и
     * сервер терял ещё не прочитанные кадры — из нескольких публикаций доходила только первая.
     */
    public function close(): void
    {
        if ($this->socket === null) {
            return;
        }

        try {
            $this->writeNonBlocking($this->socket, WebSocketFrame::encode(pack('n', 1000), true, WebSocketFrame::OPCODE_CLOSE));
            $this->awaitServerClose();
        } catch (Throwable) {
            // The connection is already broken: nothing is left to deliver.
        }

        $this->closeSocket();
    }

    /** Читает входящие кадры до close сервера или конца потока; ping по пути не обязателен к ответу. */
    private function awaitServerClose(): void
    {
        $deadline = microtime(true) + $this->options->closeTimeoutSeconds;
        while ($this->socket !== null) {
            while (($frame = WebSocketFrame::decode($this->buffer)) !== null) {
                $this->buffer = substr($this->buffer, $frame['frameLength']);
                if ($frame['opcode'] === WebSocketFrame::OPCODE_CLOSE) {
                    return;
                }
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return;
            }

            $read                     = [$this->socket];
            $write                    = null;
            $except                   = null;
            [$seconds, $microseconds] = $this->splitTimeout($remaining);
            if (stream_select($read, $write, $except, $seconds, $microseconds) < 1) {
                return;
            }

            $data = fread($this->socket, 8192);
            if ($data === '' || $data === false) {
                // The server closed the stream: its side is done.
                return;
            }

            $this->buffer .= $data;
        }
    }

    private function closeSocket(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
            $this->buffer = '';
        }
    }

    public function subscribe(string $channel, ?string $auth = null, mixed $channelData = null): void
    {
        $message = [
            'type'    => 'subscribe',
            'channel' => $channel,
        ];

        if ($auth !== null) {
            $message['auth'] = $auth;
        }

        if ($channelData !== null) {
            $message['channel_data'] = $channelData;
        }

        $this->send($message);
    }

    public function publish(string $channel, string $event, mixed $data = null): void
    {
        $this->send([
            'type'    => 'publish',
            'channel' => $channel,
            'event'   => $event,
            'data'    => $data,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function receive(float $timeoutSeconds = 0.0): ?array
    {
        if ($this->socket === null) {
            return null;
        }

        $message = $this->decodeBufferedMessage();
        if ($message !== null || $this->socket === null) {
            return $message;
        }

        $data = fread($this->socket, 8192);
        if ($data === false) {
            throw new RuntimeException('Unable to read from Pushr server.');
        }

        if ($data !== '') {
            $this->buffer .= $data;
            $message = $this->decodeBufferedMessage();
            if ($message !== null || $this->socket === null) {
                return $message;
            }
        }

        $read                     = [$this->socket];
        $write                    = null;
        $except                   = null;
        [$seconds, $microseconds] = $this->splitTimeout($timeoutSeconds);
        $selected                 = stream_select($read, $write, $except, $seconds, $microseconds);
        if ($selected === false) {
            throw new RuntimeException('Unable to wait for data from Pushr server.');
        }

        if ($selected === 0) {
            return null;
        }

        $data = fread($this->socket, 8192);
        if ($data === '' || $data === false) {
            return null;
        }

        $this->buffer .= $data;

        return $this->decodeBufferedMessage();
    }

    /**
     * Разбирает буфер до первого JSON-сообщения. На ping сервера отвечает pong с тем же payload; close-кадр
     * сервера закрывает соединение (после него receive() возвращает null, публикация требует connect()).
     *
     * @return array<string, mixed>|null
     */
    private function decodeBufferedMessage(): ?array
    {
        while ($this->socket !== null) {
            $frame = WebSocketFrame::decode($this->buffer);
            if ($frame === null) {
                return null;
            }

            $this->buffer = substr($this->buffer, $frame['frameLength']);
            if ($frame['opcode'] === WebSocketFrame::OPCODE_PING) {
                $this->writeNonBlocking(
                    $this->socket,
                    WebSocketFrame::encode($frame['payload'], true, WebSocketFrame::OPCODE_PONG),
                );

                continue;
            }

            if ($frame['opcode'] === WebSocketFrame::OPCODE_CLOSE) {
                $this->acknowledgeClose($frame['payload']);

                return null;
            }

            if ($frame['opcode'] !== WebSocketFrame::OPCODE_TEXT) {
                continue;
            }

            $payload = $frame['payload'];
            $decoded = json_decode($payload, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Отвечает на close сервера тем же кодом (без ожидания доставки) и закрывает сокет.
     */
    private function acknowledgeClose(string $payload): void
    {
        try {
            $this->writeNonBlocking(
                $this->socket,
                WebSocketFrame::encode(substr($payload, 0, 2), true, WebSocketFrame::OPCODE_CLOSE),
            );
        } catch (Throwable) {
            // Сервер уже закрывает соединение: недоставленный ответный close не ошибка.
        }

        $this->closeSocket();
    }

    /**
     * @param array<string, mixed> $message
     */
    private function send(array $message): void
    {
        if ($this->socket === null) {
            throw new RuntimeException('Pushr client is not connected.');
        }

        $payload = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('Failed to encode Pushr message.');
        }

        $frame = WebSocketFrame::encode($payload, true);
        $this->writeNonBlocking($this->socket, $frame);
    }

    /** @param resource $socket */
    private function writeNonBlocking($socket, string $data): void
    {
        $length   = strlen($data);
        $offset   = 0;
        $deadline = microtime(true) + $this->options->writeTimeoutSeconds;

        while ($offset < $length) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('Pushr write timed out.');
            }

            $read                     = null;
            $write                    = [$socket];
            $except                   = null;
            [$seconds, $microseconds] = $this->splitTimeout($remaining);
            $selected                 = stream_select($read, $write, $except, $seconds, $microseconds);
            if ($selected === false) {
                throw new RuntimeException('Unable to wait for Pushr socket write readiness.');
            }

            if ($selected === 0) {
                throw new RuntimeException('Pushr write timed out.');
            }

            $written = fwrite($socket, substr($data, $offset));
            if ($written === false) {
                throw new RuntimeException('Unable to write to Pushr server.');
            }

            $offset += $written;
        }
    }

    /**
     * @return array{0:int,1:int}
     */
    private function splitTimeout(float $timeoutSeconds): array
    {
        if ($timeoutSeconds <= 0) {
            return [0, 0];
        }

        $seconds      = (int) $timeoutSeconds;
        $microseconds = (int) (($timeoutSeconds - $seconds) * 1_000_000);

        return [$seconds, $microseconds];
    }

    /** @param resource $socket */
    private function setTimeout($socket, float $timeoutSeconds): void
    {
        [$seconds, $microseconds] = $this->splitTimeout($timeoutSeconds);
        stream_set_timeout($socket, $seconds, $microseconds);
    }

    /** @param resource $socket */
    private function readHandshakeLine($socket, float $deadline): string
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('Pushr handshake timed out.');
        }

        $this->setTimeout($socket, $remaining);
        $line = stream_get_line($socket, 4096, "\r\n");
        if ($line === false) {
            $this->throwReadFailure($socket, 'Pushr handshake timed out.', 'Pushr handshake failed.');
        }

        return $line;
    }

    /** @param resource $socket */
    private function throwReadFailure($socket, string $timeoutMessage, string $failureMessage): never
    {
        $metadata = stream_get_meta_data($socket);

        throw new RuntimeException(($metadata['timed_out'] ?? false) ? $timeoutMessage : $failureMessage);
    }
}
