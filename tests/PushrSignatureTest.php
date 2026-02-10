<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use PhpSoftBox\Broadcaster\Pushr\PushrSignature;
use PHPUnit\Framework\TestCase;

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
}
