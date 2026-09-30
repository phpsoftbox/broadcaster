<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use InvalidArgumentException;
use PhpSoftBox\Broadcaster\Pushr\WebSocketFrame;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function pack;
use function str_repeat;

#[CoversClass(WebSocketFrame::class)]
#[CoversMethod(WebSocketFrame::class, 'decode')]
#[CoversMethod(WebSocketFrame::class, 'encode')]
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

    /**
     * Проверим, что ping кодируется с opcode 9 и payload сохраняется при маскировании.
     *
     * @see WebSocketFrame::encode()
     * @see WebSocketFrame::decode()
     */
    #[Test]
    public function encodeDecodePingWithPayload(): void
    {
        $decoded = WebSocketFrame::decode(WebSocketFrame::encode('probe', true, WebSocketFrame::OPCODE_PING));

        self::assertNotNull($decoded);
        self::assertSame(WebSocketFrame::OPCODE_PING, $decoded['opcode']);
        self::assertSame('probe', $decoded['payload']);
    }

    /**
     * Проверим, что pong кодируется с FIN и opcode 10.
     *
     * @see WebSocketFrame::encode()
     * @see WebSocketFrame::decode()
     */
    #[Test]
    public function encodeDecodePongWithPayload(): void
    {
        $frame   = WebSocketFrame::encode('probe', false, WebSocketFrame::OPCODE_PONG);
        $decoded = WebSocketFrame::decode($frame);

        self::assertSame("\x8A\x05probe", $frame);
        self::assertNotNull($decoded);
        self::assertSame(WebSocketFrame::OPCODE_PONG, $decoded['opcode']);
        self::assertSame('probe', $decoded['payload']);
    }

    /**
     * Проверим, что close кодируется с opcode 8 и кодом закрытия в payload.
     *
     * @see WebSocketFrame::encode()
     * @see WebSocketFrame::decode()
     */
    #[Test]
    public function encodeDecodeCloseWithStatusCode(): void
    {
        $decoded = WebSocketFrame::decode(WebSocketFrame::encode(pack('n', 1001), false, WebSocketFrame::OPCODE_CLOSE));

        self::assertNotNull($decoded);
        self::assertSame(WebSocketFrame::OPCODE_CLOSE, $decoded['opcode']);
        self::assertSame(pack('n', 1001), $decoded['payload']);
    }

    /**
     * Проверим, что control-кадр с payload длиннее 125 байт отклоняется (RFC 6455 §5.5).
     *
     * @see WebSocketFrame::encode()
     */
    #[Test]
    public function encodeRejectsControlFrameLongerThan125Bytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WebSocketFrame::encode(str_repeat('a', 126), false, WebSocketFrame::OPCODE_PING);
    }

    /**
     * Проверим, что opcode вне 4-битного диапазона отклоняется.
     *
     * @see WebSocketFrame::encode()
     */
    #[Test]
    public function encodeRejectsOpcodeOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WebSocketFrame::encode('', false, 0x10);
    }
}
