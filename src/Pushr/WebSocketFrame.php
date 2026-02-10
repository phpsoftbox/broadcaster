<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Pushr;

use function chr;
use function is_array;
use function ord;
use function pack;
use function random_bytes;
use function strlen;
use function substr;
use function unpack;

final class WebSocketFrame
{
    /**
     * @return array{payload:string,opcode:int,frameLength:int}|null
     */
    public static function decode(string $buffer): ?array
    {
        if (strlen($buffer) < 2) {
            return null;
        }

        $first  = ord($buffer[0]);
        $second = ord($buffer[1]);

        $opcode = $first & 0x0f;
        $masked = ($second & 0x80) !== 0;
        $length = $second & 0x7f;
        $offset = 2;

        if ($length === 126) {
            if (strlen($buffer) < 4) {
                return null;
            }
            $length = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($buffer) < 10) {
                return null;
            }
            $lengthParts = unpack('J', substr($buffer, 2, 8));
            $length      = is_array($lengthParts) ? (int) ($lengthParts[1] ?? 0) : 0;
            $offset      = 10;
        }

        $mask = '';
        if ($masked) {
            if (strlen($buffer) < $offset + 4) {
                return null;
            }
            $mask = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if (strlen($buffer) < $offset + $length) {
            return null;
        }

        $payload = substr($buffer, $offset, $length);
        if ($masked && $mask !== '') {
            $payload = self::applyMask($payload, $mask);
        }

        return [
            'payload'     => $payload,
            'opcode'      => $opcode,
            'frameLength' => $offset + $length,
        ];
    }

    public static function encode(string $payload, bool $masked): string
    {
        $length = strlen($payload);
        $first  = chr(0x81);

        if ($length <= 125) {
            $header = chr(($masked ? 0x80 : 0x00) | $length);
        } elseif ($length <= 65535) {
            $header = chr(($masked ? 0x80 : 0x00) | 126) . pack('n', $length);
        } else {
            $header = chr(($masked ? 0x80 : 0x00) | 127) . pack('J', $length);
        }

        if (!$masked) {
            return $first . $header . $payload;
        }

        $mask          = random_bytes(4);
        $maskedPayload = self::applyMask($payload, $mask);

        return $first . $header . $mask . $maskedPayload;
    }

    private static function applyMask(string $payload, string $mask): string
    {
        $out    = '';
        $length = strlen($payload);

        for ($i = 0; $i < $length; $i++) {
            $out .= $payload[$i] ^ $mask[$i % 4];
        }

        return $out;
    }
}
