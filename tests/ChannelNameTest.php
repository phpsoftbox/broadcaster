<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use PhpSoftBox\Broadcaster\Channel\PresenceChannel;
use PhpSoftBox\Broadcaster\Channel\PrivateChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PrivateChannel::class)]
#[CoversClass(PresenceChannel::class)]
final class ChannelNameTest extends TestCase
{
    #[Test]
    public function privateChannelAddsPrefix(): void
    {
        $channel = new PrivateChannel('admin.user.10');

        $this->assertSame('private.admin.user.10', (string) $channel);
    }

    #[Test]
    public function privateChannelKeepsExistingPrefix(): void
    {
        $channel = new PrivateChannel('private.admin.user.10');

        $this->assertSame('private.admin.user.10', (string) $channel);
    }

    #[Test]
    public function presenceChannelAddsPrefix(): void
    {
        $channel = new PresenceChannel('chat.room.1');

        $this->assertSame('presence.chat.room.1', (string) $channel);
    }

    #[Test]
    public function presenceChannelKeepsExistingPrefix(): void
    {
        $channel = new PresenceChannel('presence.chat.room.1');

        $this->assertSame('presence.chat.room.1', (string) $channel);
    }
}
