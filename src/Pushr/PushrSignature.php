<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Pushr;

use function hash_equals;
use function hash_hmac;
use function time;

/**
 * Подписи handshake. Обычная (`generate`) выдаётся браузеру и даёт только подписку; подпись публикатора
 * (`generatePublisher`) формируется только на бэкенде и разрешает соединению `publish`.
 */
final class PushrSignature
{
    public static function generate(string $appId, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $appId . ':' . $timestamp, $secret);
    }

    public static function verify(string $appId, string $secret, int $timestamp, string $signature, int $maxSkew = 300): bool
    {
        if (!self::withinSkew($timestamp, $maxSkew)) {
            return false;
        }

        return hash_equals(self::generate($appId, $secret, $timestamp), $signature);
    }

    public static function generatePublisher(string $appId, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', 'publisher:' . $appId . ':' . $timestamp, $secret);
    }

    public static function verifyPublisher(
        string $appId,
        string $secret,
        int $timestamp,
        string $signature,
        int $maxSkew = 300,
    ): bool {
        if (!self::withinSkew($timestamp, $maxSkew)) {
            return false;
        }

        return hash_equals(self::generatePublisher($appId, $secret, $timestamp), $signature);
    }

    private static function withinSkew(int $timestamp, int $maxSkew): bool
    {
        if ($maxSkew <= 0) {
            return true;
        }

        $now = time();

        return $timestamp >= $now - $maxSkew && $timestamp <= $now + $maxSkew;
    }
}
