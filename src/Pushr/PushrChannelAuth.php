<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Pushr;

use function explode;
use function hash_equals;
use function hash_hmac;
use function is_string;
use function json_encode;
use function str_contains;
use function trim;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class PushrChannelAuth
{
    public static function signature(
        string $socketId,
        string $channel,
        string $secret,
        mixed $channelData = null,
    ): string {
        $payload = $socketId . ':' . $channel;
        $data    = self::normalizeChannelData($channelData);
        if ($data !== null) {
            $payload .= ':' . $data;
        }

        return hash_hmac('sha256', $payload, $secret);
    }

    public static function token(
        string $appId,
        string $secret,
        string $socketId,
        string $channel,
        mixed $channelData = null,
    ): string {
        $signature = self::signature($socketId, $channel, $secret, $channelData);

        return $appId . ':' . $signature;
    }

    public static function verify(
        string $appId,
        string $secret,
        string $socketId,
        string $channel,
        string $auth,
        mixed $channelData = null,
    ): bool {
        $auth = trim($auth);
        if ($auth === '' || !str_contains($auth, ':')) {
            return false;
        }

        [$authAppId, $signature] = explode(':', $auth, 2);
        if ($authAppId !== $appId || $signature === '') {
            return false;
        }

        $expected = self::signature($socketId, $channel, $secret, $channelData);

        return hash_equals($expected, $signature);
    }

    private static function normalizeChannelData(mixed $channelData): ?string
    {
        if ($channelData === null) {
            return null;
        }

        if (is_string($channelData)) {
            $trimmed = trim($channelData);

            return $trimmed === '' ? null : $channelData;
        }

        $encoded = json_encode($channelData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }
}
