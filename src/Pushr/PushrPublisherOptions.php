<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Pushr;

use InvalidArgumentException;

final readonly class PushrPublisherOptions
{
    public function __construct(
        public float $connectTimeoutSeconds = 1.0,
        public float $handshakeTimeoutSeconds = 1.0,
        public float $readTimeoutSeconds = 1.0,
        public float $writeTimeoutSeconds = 1.0,
    ) {
        if ($this->connectTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('Pushr connect timeout must be greater than zero.');
        }

        if ($this->handshakeTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('Pushr handshake timeout must be greater than zero.');
        }

        if ($this->readTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('Pushr read timeout must be greater than zero.');
        }

        if ($this->writeTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('Pushr write timeout must be greater than zero.');
        }
    }
}
