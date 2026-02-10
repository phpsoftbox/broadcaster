<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use PhpSoftBox\Broadcaster\Cli\PushrServeRegistryHandler;
use PhpSoftBox\Broadcaster\Contracts\PushrRegistryBuilderInterface;
use PhpSoftBox\Broadcaster\Pushr\PushrAppRegistry;
use PhpSoftBox\CliApp\Command\DaemonStartupException;
use PhpSoftBox\CliApp\Io\IoInterface;
use PhpSoftBox\CliApp\Io\NullIo;
use PhpSoftBox\CliApp\Request\Request;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PushrServeRegistryHandlerTest extends TestCase
{
    /**
     * Передает опции в registry builder без переименования CLI-ключей.
     */
    #[Test]
    public function testPassesOptionsToRegistryBuilderWithoutRenamingKeys(): void
    {
        $state = new class () {
            /** @var array<string, mixed>|null */
            public ?array $options = null;
        };

        $builder = new class ($state) implements PushrRegistryBuilderInterface {
            public function __construct(
                private object $state,
            ) {
            }

            public function build(array $options = []): PushrAppRegistry
            {
                $this->state->options = $options;

                throw new RuntimeException('stop');
            }
        };

        $handler = new PushrServeRegistryHandler($builder);
        $runner  = new class () implements RunnerInterface {
            public function run(string $command, array $argv): Response
            {
                return new Response(Response::SUCCESS);
            }

            public function runSubCommand(string $command, array $argv): Response
            {
                return new Response(Response::SUCCESS);
            }

            public function request(): Request
            {
                return new Request([], [
                    'host'                => '0.0.0.0',
                    'port'                => 8080,
                    'max-skew'            => 300,
                    'without-default-app' => true,
                ]);
            }

            public function io(): IoInterface
            {
                return new NullIo();
            }
        };

        try {
            $handler->runAsDaemon($runner);
            self::fail('Expected daemon startup exception.');
        } catch (DaemonStartupException $exception) {
            self::assertSame(Response::FAILURE, $exception->responseCode());
        }

        self::assertSame([
            'host'                => '0.0.0.0',
            'port'                => 8080,
            'max-skew'            => 300,
            'without-default-app' => true,
        ], $state->options);
    }
}
