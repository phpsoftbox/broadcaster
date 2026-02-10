<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Pushr;

use RuntimeException;

use function base64_encode;
use function fclose;
use function fread;
use function fwrite;
use function http_build_query;
use function is_array;
use function json_decode;
use function json_encode;
use function random_bytes;
use function sprintf;
use function str_contains;
use function stream_get_line;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_client;
use function substr;
use function time;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class PushrClient
{
    /** @var resource|null */
    private $socket = null;

    private string $buffer = '';

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $appId,
        private readonly string $secret,
        private readonly string $path = '/',
    ) {
    }

    public function connect(): void
    {
        $socket = stream_socket_client(sprintf('tcp://%s:%d', $this->host, $this->port), $errno, $errstr, 5);
        if ($socket === false) {
            throw new RuntimeException('Unable to connect to Pushr server: ' . $errstr);
        }

        stream_set_blocking($socket, true);

        $timestamp = time();
        $signature = PushrSignature::generate($this->appId, $this->secret, $timestamp);
        $query     = http_build_query([
            'app_id'    => $this->appId,
            'timestamp' => $timestamp,
            'signature' => $signature,
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

        fwrite($socket, $request);

        $statusLine = stream_get_line($socket, 4096, "\r\n");
        if ($statusLine === false || !str_contains($statusLine, '101')) {
            fclose($socket);

            throw new RuntimeException('Pushr handshake failed: ' . (string) $statusLine);
        }

        while (true) {
            $line = stream_get_line($socket, 4096, "\r\n");
            if ($line === false || $line === '') {
                break;
            }
        }

        stream_set_blocking($socket, false);
        $this->socket = $socket;
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
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

    public function publish(
        string $channel,
        string $event,
        mixed $data = null,
        ?string $auth = null,
        mixed $channelData = null,
    ): void {
        $message = [
            'type'    => 'publish',
            'channel' => $channel,
            'event'   => $event,
            'data'    => $data,
        ];

        if ($auth !== null) {
            $message['auth'] = $auth;
        }

        if ($channelData !== null) {
            $message['channel_data'] = $channelData;
        }

        $this->send($message);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function receive(int $timeoutSeconds = 0): ?array
    {
        if ($this->socket === null) {
            return null;
        }

        $read   = [$this->socket];
        $write  = null;
        $except = null;
        if ($timeoutSeconds > 0) {
            if (stream_select($read, $write, $except, $timeoutSeconds) === 0) {
                return null;
            }
        } else {
            if (stream_select($read, $write, $except, 0) === 0) {
                return null;
            }
        }

        $data = fread($this->socket, 8192);
        if ($data === '' || $data === false) {
            return null;
        }

        $this->buffer .= $data;
        $frame = WebSocketFrame::decode($this->buffer);
        if ($frame === null) {
            return null;
        }

        $this->buffer = substr($this->buffer, $frame['frameLength']);
        if ($frame['opcode'] !== 1) {
            return null;
        }

        $payload = $frame['payload'];
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
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
        fwrite($this->socket, $frame);
    }
}
