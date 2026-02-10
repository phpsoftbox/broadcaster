<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Pushr;

use InvalidArgumentException;
use Stringable;

use function array_values;
use function is_array;
use function is_string;
use function trim;

final readonly class PushrPublication
{
    /** @var non-empty-list<string> */
    public array $channels;

    /**
     * @param iterable<string|Stringable> $channels
     */
    public function __construct(
        iterable $channels,
        public string $event,
        public mixed $data = null,
    ) {
        $normalized = [];
        foreach ($channels as $channel) {
            if (!is_string($channel) && !$channel instanceof Stringable) {
                throw new InvalidArgumentException('Pushr channel must be a string or Stringable object.');
            }

            $name = trim((string) $channel);
            if ($name === '') {
                throw new InvalidArgumentException('Pushr channel name must not be empty.');
            }

            $normalized[$name] = $name;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('At least one Pushr channel is required.');
        }

        if (trim($this->event) === '') {
            throw new InvalidArgumentException('Pushr event name must not be empty.');
        }

        $this->channels = array_values($normalized);
    }

    /**
     * @return array{channels:non-empty-list<string>,event:string,data:mixed}
     */
    public function toArray(): array
    {
        return [
            'channels' => $this->channels,
            'event'    => $this->event,
            'data'     => $this->data,
        ];
    }

    /**
     * Restores a publication read from a queue or outbox payload.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $channels = $payload['channels'] ?? null;
        $event    = $payload['event'] ?? null;
        if (!is_array($channels) || !is_string($event)) {
            throw new InvalidArgumentException('Invalid Pushr publication payload.');
        }

        return new self($channels, $event, $payload['data'] ?? null);
    }
}
