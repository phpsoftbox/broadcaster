<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Pushr;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

use function array_shift;
use function base64_encode;
use function bin2hex;
use function count;
use function explode;
use function fclose;
use function fread;
use function fwrite;
use function hash;
use function is_array;
use function json_decode;
use function json_encode;
use function parse_str;
use function parse_url;
use function random_bytes;
use function sprintf;
use function str_starts_with;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_server;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function time;
use function trim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class PushrServer
{
    private const int HANDSHAKE_TIMEOUT_SECONDS = 5;
    private const int MAX_HANDSHAKE_BYTES       = 16384;
    private const int MAX_FRAME_BYTES           = 1048576;

    /** @var array<int, PushrConnection> */
    private array $clients = [];

    /** @var array<string, array<string, array<string, true>>> app_id => channel => client_id */
    private array $channels = [];

    /** @var array<int, array{socket:resource, buffer:string, deadline:int}> незавершённые HTTP-handshake */
    private array $handshakes = [];

    public function __construct(
        private readonly PushrAppRegistry $apps,
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 8080,
        private readonly int $maxSkew = 300,
    ) {
    }

    public function run(): void
    {
        $server = stream_socket_server(sprintf('tcp://%s:%d', $this->host, $this->port), $errno, $errstr);
        if ($server === false) {
            throw new RuntimeException('Unable to start Pushr server: ' . $errstr);
        }

        stream_set_blocking($server, false);

        while (true) {
            $this->dropExpiredHandshakes();

            $read = [$server];
            foreach ($this->handshakes as $handshake) {
                $read[] = $handshake['socket'];
            }
            foreach ($this->clients as $client) {
                $read[] = $client->socket;
            }

            $write  = null;
            $except = null;
            if (stream_select($read, $write, $except, 1) === false) {
                continue;
            }

            foreach ($read as $socket) {
                if ($socket === $server) {
                    $this->accept($server);
                    continue;
                }

                if (isset($this->handshakes[(int) $socket])) {
                    $this->readHandshake($socket);
                    continue;
                }

                $client = $this->findClientBySocket($socket);
                if ($client === null) {
                    fclose($socket);
                    continue;
                }

                $this->readClient($client);
            }
        }
    }

    /**
     * Принимает TCP-соединение без ожидания запроса: handshake дочитывается из цикла select, чтобы медленный
     * или молчащий клиент не блокировал остальных.
     */
    private function accept($server): void
    {
        $socket = @stream_socket_accept($server, 0);
        if ($socket === false) {
            return;
        }

        stream_set_blocking($socket, false);

        $this->handshakes[(int) $socket] = [
            'socket'   => $socket,
            'buffer'   => '',
            'deadline' => time() + self::HANDSHAKE_TIMEOUT_SECONDS,
        ];
    }

    private function readHandshake($socket): void
    {
        $key  = (int) $socket;
        $data = fread($socket, 8192);
        if ($data === '' || $data === false) {
            unset($this->handshakes[$key]);
            fclose($socket);

            return;
        }

        $buffer = $this->handshakes[$key]['buffer'] . $data;
        $end    = strpos($buffer, "\r\n\r\n");
        if ($end === false) {
            if (strlen($buffer) > self::MAX_HANDSHAKE_BYTES) {
                unset($this->handshakes[$key]);
                $this->reject($socket, 431, 'Request Header Fields Too Large');

                return;
            }

            $this->handshakes[$key]['buffer'] = $buffer;

            return;
        }

        unset($this->handshakes[$key]);

        $request = $this->parseHttpRequest(substr($buffer, 0, $end));
        if ($request === null) {
            fclose($socket);

            return;
        }

        $this->completeHandshake($socket, $request[0], $request[1]);
    }

    private function dropExpiredHandshakes(): void
    {
        $now = time();
        foreach ($this->handshakes as $key => $handshake) {
            if ($handshake['deadline'] >= $now) {
                continue;
            }

            unset($this->handshakes[$key]);
            fclose($handshake['socket']);
        }
    }

    private function readClient(PushrConnection $client): void
    {
        $data = fread($client->socket, 8192);
        if ($data === '' || $data === false) {
            $this->close($client);

            return;
        }

        $client->buffer .= $data;
        while (true) {
            try {
                $frame = WebSocketFrame::decode($client->buffer, self::MAX_FRAME_BYTES);
            } catch (InvalidArgumentException) {
                // Некорректная или слишком большая длина кадра: без закрытия буфер рос бы бесконечно.
                $this->close($client);

                return;
            }

            if ($frame === null) {
                if (strlen($client->buffer) > self::MAX_FRAME_BYTES + 14) {
                    $this->close($client);
                }

                return;
            }

            $client->buffer = substr($client->buffer, $frame['frameLength']);
            $this->handleFrame($client, $frame['opcode'], $frame['payload']);
            if (!isset($this->clients[(int) $client->socket])) {
                return;
            }
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function completeHandshake($socket, string $path, array $headers): void
    {
        $query    = [];
        $urlParts = parse_url($path);
        if (is_array($urlParts) && isset($urlParts['query'])) {
            parse_str((string) $urlParts['query'], $query);
        }

        $appId     = (string) ($query['app_id'] ?? '');
        $timestamp = (int) ($query['timestamp'] ?? 0);
        $signature = (string) ($query['signature'] ?? '');

        $secret = $this->apps->secret($appId);
        if ($appId === '' || $secret === null || $timestamp === 0 || $signature === '') {
            $this->reject($socket, 401, 'Unauthorized');

            return;
        }

        $publisher = ($query['role'] ?? null) === 'publisher';
        $verified  = $publisher
            ? PushrSignature::verifyPublisher($appId, $secret, $timestamp, $signature, $this->maxSkew)
            : PushrSignature::verify($appId, $secret, $timestamp, $signature, $this->maxSkew);
        if (!$verified) {
            $this->reject($socket, 401, 'Invalid signature');

            return;
        }

        $key = $headers['sec-websocket-key'] ?? '';
        if ($key === '') {
            $this->reject($socket, 400, 'Missing Sec-WebSocket-Key');

            return;
        }

        $accept = base64_encode(hash('sha1', $key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        $response = "HTTP/1.1 101 Switching Protocols\r\n";
        $response .= "Upgrade: websocket\r\n";
        $response .= "Connection: Upgrade\r\n";
        $response .= "Sec-WebSocket-Accept: {$accept}\r\n\r\n";
        fwrite($socket, $response);

        $id     = bin2hex(random_bytes(8));
        $client = new PushrConnection($socket, $id, $appId, $publisher);

        $this->clients[(int) $socket] = $client;

        $this->send($client, [
            'type'      => 'connection',
            'socket_id' => $id,
            'timestamp' => time(),
        ]);
    }

    /**
     * @return array{0:string, 1:array<string, string>}|null
     */
    private function parseHttpRequest(string $head): ?array
    {
        $lines = explode("\r\n", $head);
        $parts = explode(' ', (string) array_shift($lines), 3);
        if (count($parts) < 2) {
            return null;
        }

        $headers = [];
        foreach ($lines as $header) {
            $pos = strpos($header, ':');
            if ($pos === false) {
                continue;
            }

            $headers[strtolower(trim(substr($header, 0, $pos)))] = trim(substr($header, $pos + 1));
        }

        return [$parts[1], $headers];
    }

    private function reject($socket, int $status, string $message): void
    {
        $response = "HTTP/1.1 {$status} {$message}\r\nConnection: close\r\n\r\n";
        fwrite($socket, $response);
        fclose($socket);
    }

    private function handleFrame(PushrConnection $client, int $opcode, string $payload): void
    {
        if ($opcode === 8) {
            $this->close($client);

            return;
        }

        if ($opcode !== 1) {
            return;
        }

        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->send($client, ['type' => 'error', 'message' => 'Invalid JSON']);

            return;
        }

        if (!is_array($data)) {
            return;
        }

        $type = $data['type'] ?? null;
        if ($type === 'subscribe' && isset($data['channel'])) {
            $channel     = (string) $data['channel'];
            $auth        = isset($data['auth']) ? (string) $data['auth'] : null;
            $channelData = $data['channel_data'] ?? null;
            $this->subscribe($client, $channel, $auth, $channelData);

            return;
        }

        if ($type === 'unsubscribe' && isset($data['channel'])) {
            $channel = (string) $data['channel'];
            $this->unsubscribe($client, $channel);

            return;
        }

        if ($type === 'publish' && isset($data['channel'])) {
            // Публикует только бэкенд: auth канала выдаётся браузеру для подписки, и с ним подписчик
            // мог бы рассылать поддельные события остальным.
            if (!$client->publisher) {
                $this->send($client, ['type' => 'error', 'message' => 'Publish is not allowed for this connection']);

                return;
            }

            $channel = (string) $data['channel'];
            $event   = isset($data['event']) ? (string) $data['event'] : 'message';

            $this->broadcast($client->appId, $channel, [
                'type'    => 'event',
                'channel' => $channel,
                'event'   => $event,
                'data'    => $data['data'] ?? null,
            ]);
        }
    }

    private function subscribe(
        PushrConnection $client,
        string $channel,
        ?string $auth = null,
        mixed $channelData = null,
    ): void {
        if ($channel === '') {
            return;
        }

        if ($this->requiresChannelAuth($channel)) {
            $secret = $this->apps->secret($client->appId);
            if ($secret === null) {
                $this->send($client, ['type' => 'error', 'message' => 'Unauthorized channel']);

                return;
            }

            if ($auth === null || $auth === '') {
                $this->send($client, ['type' => 'error', 'message' => 'Channel auth is required']);

                return;
            }

            if (!PushrChannelAuth::verify($client->appId, $secret, $client->id, $channel, $auth, $channelData)) {
                $this->send($client, ['type' => 'error', 'message' => 'Invalid channel auth']);

                return;
            }
        }

        $client->channels[$channel] = true;

        $this->channels[$client->appId][$channel][$client->id] = true;

        $this->send($client, ['type' => 'subscribed', 'channel' => $channel]);
    }

    private function requiresChannelAuth(string $channel): bool
    {
        return str_starts_with($channel, 'private.')
            || str_starts_with($channel, 'presence.');
    }

    private function unsubscribe(PushrConnection $client, string $channel): void
    {
        unset($client->channels[$channel]);
        unset($this->channels[$client->appId][$channel][$client->id]);

        if (isset($this->channels[$client->appId][$channel]) && $this->channels[$client->appId][$channel] === []) {
            unset($this->channels[$client->appId][$channel]);
        }
        if (isset($this->channels[$client->appId]) && $this->channels[$client->appId] === []) {
            unset($this->channels[$client->appId]);
        }

        $this->send($client, ['type' => 'unsubscribed', 'channel' => $channel]);
    }

    private function broadcast(string $appId, string $channel, array $message): void
    {
        if (!isset($this->channels[$appId][$channel])) {
            return;
        }

        foreach ($this->channels[$appId][$channel] as $clientId => $_) {
            $client = $this->findClientById($clientId);
            if ($client === null) {
                continue;
            }

            $this->send($client, $message);
        }
    }

    private function send(PushrConnection $client, array $message): void
    {
        $payload = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }

        $frame = WebSocketFrame::encode($payload, false);
        @fwrite($client->socket, $frame);
    }

    private function close(PushrConnection $client): void
    {
        foreach ($client->channels as $channel => $_) {
            unset($this->channels[$client->appId][$channel][$client->id]);
            if (isset($this->channels[$client->appId][$channel]) && $this->channels[$client->appId][$channel] === []) {
                unset($this->channels[$client->appId][$channel]);
            }
        }

        if (isset($this->channels[$client->appId]) && $this->channels[$client->appId] === []) {
            unset($this->channels[$client->appId]);
        }

        unset($this->clients[(int) $client->socket]);
        fclose($client->socket);
    }

    private function findClientBySocket($socket): ?PushrConnection
    {
        return $this->clients[(int) $socket] ?? null;
    }

    private function findClientById(int|string $id): ?PushrConnection
    {
        foreach ($this->clients as $client) {
            if ($client->id === (string) $id) {
                return $client;
            }
        }

        return null;
    }
}
