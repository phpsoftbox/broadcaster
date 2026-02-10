<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use PhpSoftBox\Broadcaster\Pushr\PushrChannelAuth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PushrChannelAuth::class)]
final class PushrChannelAuthTest extends TestCase
{
    /**
     * Проверяет генерацию и валидацию подписи приватного канала.
     */
    #[Test]
    public function testTokenVerificationForPrivateChannel(): void
    {
        $appId    = 'app-1';
        $secret   = 'secret-1';
        $socketId = 'socket-123';
        $channel  = 'private-users';

        $auth = PushrChannelAuth::token($appId, $secret, $socketId, $channel);

        $this->assertTrue(
            PushrChannelAuth::verify($appId, $secret, $socketId, $channel, $auth),
        );
    }

    /**
     * Проверяет подпись presence-канала с channel_data.
     */
    #[Test]
    public function testTokenVerificationForPresenceChannel(): void
    {
        $appId       = 'app-1';
        $secret      = 'secret-1';
        $socketId    = 'socket-456';
        $channel     = 'presence-chat';
        $channelData = ['user_id' => 10, 'name' => 'Anton'];

        $auth = PushrChannelAuth::token($appId, $secret, $socketId, $channel, $channelData);

        $this->assertTrue(
            PushrChannelAuth::verify($appId, $secret, $socketId, $channel, $auth, $channelData),
        );
    }
}
