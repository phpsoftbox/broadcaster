<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Pushr;

use RuntimeException;

use function is_array;
use function is_string;

final readonly class PushrPublisher
{
    public function __construct(
        private string $appId,
        private string $secret,
        private string $host,
        private int $port = 8080,
        private string $path = '/',
    ) {
    }

    public function publish(string $channel, string $event, mixed $data = null): void
    {
        if ($this->appId === '' || $this->secret === '' || $this->host === '') {
            throw new RuntimeException('Pushr credentials are not configured.');
        }

        $client = new PushrClient($this->host, $this->port, $this->appId, $this->secret, $this->path);

        $client->connect();

        $socketId = null;
        for ($attempt = 0; $attempt < 3 && $socketId === null; $attempt++) {
            $connection = $client->receive(1);
            $socketId   = is_array($connection) ? ($connection['socket_id'] ?? null) : null;
        }

        $auth = null;
        if (is_string($socketId) && $socketId !== '') {
            $auth = PushrChannelAuth::token($this->appId, $this->secret, $socketId, $channel);
        }

        $client->publish($channel, $event, $data, $auth);
        $client->close();
    }
}
