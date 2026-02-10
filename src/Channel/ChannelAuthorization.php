<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Channel;

final readonly class ChannelAuthorization
{
    /**
     * @param array<string, string> $params
     */
    public function __construct(
        private bool $authorized,
        private mixed $channelData = null,
        private ?string $pattern = null,
        private array $params = [],
    ) {
    }

    public function authorized(): bool
    {
        return $this->authorized;
    }

    public function channelData(): mixed
    {
        return $this->channelData;
    }

    public function pattern(): ?string
    {
        return $this->pattern;
    }

    /**
     * @return array<string, string>
     */
    public function params(): array
    {
        return $this->params;
    }
}
