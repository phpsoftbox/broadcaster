<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use PhpSoftBox\Broadcaster\Pushr\PushrSignature;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PushrSignature::class)]
#[CoversMethod(PushrSignature::class, 'verifyPublisher')]
final class PushrSignatureTest extends TestCase
{
    public function testGenerateAndVerify(): void
    {
        $signature = PushrSignature::generate('app', 'secret', 1000);

        $this->assertTrue(PushrSignature::verify('app', 'secret', 1000, $signature, 0));
        $this->assertFalse(PushrSignature::verify('app', 'secret', 1000, 'bad', 0));
    }

    public function testSkewValidation(): void
    {
        $signature = PushrSignature::generate('app', 'secret', 1);

        $this->assertFalse(PushrSignature::verify('app', 'secret', 1, $signature, 1));
    }

    /**
     * Проверим, что подпись публикатора проверяется только как подпись публикатора: обычная подпись браузера
     * её не заменяет.
     *
     * @see PushrSignature::verifyPublisher()
     */
    #[Test]
    public function publisherSignatureDiffersFromBrowserSignature(): void
    {
        $publisher = PushrSignature::generatePublisher('app', 'secret', 1000);
        $browser   = PushrSignature::generate('app', 'secret', 1000);

        self::assertTrue(PushrSignature::verifyPublisher('app', 'secret', 1000, $publisher, 0));
        self::assertFalse(PushrSignature::verifyPublisher('app', 'secret', 1000, $browser, 0));
        self::assertFalse(PushrSignature::verify('app', 'secret', 1000, $publisher, 0));
    }
}
