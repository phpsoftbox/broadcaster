<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Pushr;

use Closure;
use PhpSoftBox\Broadcaster\Contracts\PushrClientInterface;
use PhpSoftBox\Broadcaster\Contracts\PushrPublisherInterface;
use RuntimeException;
use Stringable;

use function is_array;
use function is_string;
use function microtime;
use function str_starts_with;

final readonly class PushrPublisher implements PushrPublisherInterface
{
    /** @var Closure(string, int, string, string, string, PushrPublisherOptions): PushrClientInterface */
    private Closure $clientFactory;

    /**
     * @param (callable(string, int, string, string, string, PushrPublisherOptions): PushrClientInterface)|null $clientFactory
     */
    public function __construct(
        private string $appId,
        private string $secret,
        private string $host,
        private int $port = 8080,
        private string $path = '/',
        private PushrPublisherOptions $options = new PushrPublisherOptions(),
        ?callable $clientFactory = null,
    ) {
        $this->clientFactory = $clientFactory !== null
            ? Closure::fromCallable($clientFactory)
            : static fn (
                string $host,
                int $port,
                string $appId,
                string $secret,
                string $path,
                PushrPublisherOptions $options,
            ): PushrClientInterface => new PushrClient($host, $port, $appId, $secret, $path, $options);
    }

    public function publish(string|Stringable $channel, string $event, mixed $data = null): void
    {
        $this->publishMany([$channel], $event, $data);
    }

    public function publishMany(iterable $channels, string $event, mixed $data = null): void
    {
        if ($this->appId === '' || $this->secret === '' || $this->host === '') {
            throw new RuntimeException('Pushr credentials are not configured.');
        }

        $publication = new PushrPublication($channels, $event, $data);
        $client      = ($this->clientFactory)(
            $this->host,
            $this->port,
            $this->appId,
            $this->secret,
            $this->path,
            $this->options,
        );
        if (!$client instanceof PushrClientInterface) {
            throw new RuntimeException('Pushr client factory must return PushrClientInterface.');
        }

        try {
            $client->connect();

            $privateChannels = [];
            foreach ($publication->channels as $channel) {
                if (str_starts_with($channel, 'private.') || str_starts_with($channel, 'presence.')) {
                    $privateChannels[$channel] = true;
                }
            }

            $socketId = $privateChannels === [] ? null : $this->receiveSocketId($client);

            foreach ($publication->channels as $channel) {
                $auth = $socketId !== null && isset($privateChannels[$channel])
                    ? PushrChannelAuth::token($this->appId, $this->secret, $socketId, $channel)
                    : null;

                $client->publish($channel, $publication->event, $publication->data, $auth);
            }
        } finally {
            $client->close();
        }
    }

    private function receiveSocketId(PushrClientInterface $client): string
    {
        $deadline = microtime(true) + $this->options->readTimeoutSeconds;

        do {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }

            $connection = $client->receive($remaining);
            if ($connection === null) {
                break;
            }

            $socketId = is_array($connection) ? ($connection['socket_id'] ?? null) : null;
            if (is_string($socketId) && $socketId !== '') {
                return $socketId;
            }
        } while (true);

        throw new RuntimeException('Pushr connection did not provide a socket id before the read timeout.');
    }
}
