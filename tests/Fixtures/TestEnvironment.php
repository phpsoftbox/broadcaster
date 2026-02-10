<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests\Fixtures;

use PhpSoftBox\Config\Contracts\EnvironmentEnumInterface;

enum TestEnvironment: string implements EnvironmentEnumInterface
{
    case Test = 'test';
}
