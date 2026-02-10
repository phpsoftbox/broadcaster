<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use PhpSoftBox\Broadcaster\Cli\PushrCommandProvider;
use PhpSoftBox\Broadcaster\Cli\PushrServeRegistryHandler;
use PhpSoftBox\CliApp\Command\InMemoryCommandRegistry;
use PHPUnit\Framework\TestCase;

final class PushrCommandProviderTest extends TestCase
{
    public function testRegistryServeCommandIsDaemon(): void
    {
        $registry = new InMemoryCommandRegistry(withDefaultCommands: false);

        new PushrCommandProvider()->register($registry);

        $command = $registry->get('pushr:serve:registry');

        $this->assertNotNull($command);
        $this->assertSame(PushrServeRegistryHandler::class, $command->handler);
        $this->assertTrue($command->asDaemon);
    }
}
