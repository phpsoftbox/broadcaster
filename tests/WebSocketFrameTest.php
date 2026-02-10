<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use PhpSoftBox\Broadcaster\Pushr\WebSocketFrame;
use PHPUnit\Framework\TestCase;

final class WebSocketFrameTest extends TestCase
{
    public function testEncodeDecodeUnmasked(): void
    {
        $frame   = WebSocketFrame::encode('hello', false);
        $decoded = WebSocketFrame::decode($frame);

        $this->assertNotNull($decoded);
        $this->assertSame('hello', $decoded['payload']);
        $this->assertSame(1, $decoded['opcode']);
    }

    public function testEncodeDecodeMasked(): void
    {
        $frame   = WebSocketFrame::encode('ping', true);
        $decoded = WebSocketFrame::decode($frame);

        $this->assertNotNull($decoded);
        $this->assertSame('ping', $decoded['payload']);
        $this->assertSame(1, $decoded['opcode']);
    }
}
