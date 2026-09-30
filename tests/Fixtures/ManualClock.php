<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests\Fixtures;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Управляемые часы: тесты таймаутов сдвигают время вручную, не завися от реального.
 */
final class ManualClock implements ClockInterface
{
    private int $timestamp;

    public function __construct(int $timestamp = 1_700_000_000)
    {
        $this->timestamp = $timestamp;
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->timestamp);
    }

    public function advance(int $seconds): void
    {
        $this->timestamp += $seconds;
    }

    public function timestamp(): int
    {
        return $this->timestamp;
    }
}
