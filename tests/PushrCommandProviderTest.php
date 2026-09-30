<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use PhpSoftBox\Broadcaster\Cli\PushrCommandProvider;
use PhpSoftBox\Broadcaster\Cli\PushrServeRegistryHandler;
use PhpSoftBox\CliApp\Command\InMemoryCommandRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PushrCommandProvider::class)]
#[CoversMethod(PushrCommandProvider::class, 'register')]
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

    /**
     * Проверим, что команды запуска сервера принимают --ping-interval и --idle-timeout с умолчаниями 25 и 60.
     *
     * @see PushrCommandProvider::register()
     */
    #[Test]
    #[DataProvider('serveCommands')]
    public function serveCommandDefinesKeepaliveOptions(string $name): void
    {
        $registry = new InMemoryCommandRegistry(withDefaultCommands: false);

        new PushrCommandProvider()->register($registry);

        $command = $registry->get($name);
        self::assertNotNull($command);

        $defaults = [];
        foreach ($command->signature->options() as $option) {
            $defaults[$option->name] = $option->default;
        }

        self::assertSame(25, $defaults['ping-interval'] ?? null);
        self::assertSame(60, $defaults['idle-timeout'] ?? null);
    }

    /**
     * @return iterable<string, array{0:string}>
     */
    public static function serveCommands(): iterable
    {
        yield 'pushr:serve' => ['pushr:serve'];
        yield 'pushr:serve:registry' => ['pushr:serve:registry'];
    }
}
