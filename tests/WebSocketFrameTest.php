<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use InvalidArgumentException;
use PhpSoftBox\Broadcaster\Pushr\WebSocketFrame;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

#[CoversClass(WebSocketFrame::class)]
#[CoversMethod(WebSocketFrame::class, 'decode')]
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

    /**
     * Проверим, что длина кадра больше лимита отклоняется до ожидания всего payload.
     *
     * @see WebSocketFrame::decode()
     */
    #[Test]
    public function decodeRejectsFrameLongerThanLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WebSocketFrame::decode(WebSocketFrame::encode(str_repeat('a', 200), false), 100);
    }

    /**
     * Проверим, что отрицательная длина из 64-битного поля отклоняется.
     *
     * @see WebSocketFrame::decode()
     */
    #[Test]
    public function decodeRejectsNegativeLength(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WebSocketFrame::decode("\x81\x7F\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xF6");
    }
}
