<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Pushr;

use function hash_equals;
use function hash_hmac;
use function time;

final class PushrSignature
{
    public static function generate(string $appId, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $appId . ':' . $timestamp, $secret);
    }

    public static function verify(string $appId, string $secret, int $timestamp, string $signature, int $maxSkew = 300): bool
    {
        if ($maxSkew > 0) {
            $now = time();
            if ($timestamp < ($now - $maxSkew) || $timestamp > ($now + $maxSkew)) {
                return false;
            }
        }

        $expected = self::generate($appId, $secret, $timestamp);

        return hash_equals($expected, $signature);
    }
}
