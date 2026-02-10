<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Cli;

use PhpSoftBox\Broadcaster\Contracts\PushrRegistryBuilderInterface;
use PhpSoftBox\Broadcaster\Pushr\PushrServer;
use PhpSoftBox\CliApp\Command\DaemonHandlerInterface;
use PhpSoftBox\CliApp\Command\DaemonStartupException;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use Throwable;

use function count;
use function is_array;
use function is_int;
use function is_string;
use function trim;

final readonly class PushrServeRegistryHandler implements DaemonHandlerInterface
{
    public function __construct(
        private PushrRegistryBuilderInterface $registryBuilder,
    ) {
    }

    public function runAsDaemon(RunnerInterface $runner): void
    {
        $host = $runner->request()->option('host', '0.0.0.0');
        if (!is_string($host) || trim($host) === '') {
            throw new DaemonStartupException('Некорректный параметр --host.', Response::INVALID_INPUT);
        }

        $port = $runner->request()->option('port', 8080);
        if (!is_int($port) || $port < 1) {
            throw new DaemonStartupException('Некорректный параметр --port.', Response::INVALID_INPUT);
        }

        $maxSkew = $runner->request()->option('max-skew', 300);
        if (!is_int($maxSkew) || $maxSkew < 0) {
            throw new DaemonStartupException('Некорректный параметр --max-skew.', Response::INVALID_INPUT);
        }

        try {
            $options  = $runner->request()->options();
            $options  = is_array($options) ? $options : [];
            $registry = $this->registryBuilder->build($options);
        } catch (Throwable $exception) {
            throw new DaemonStartupException($exception->getMessage(), Response::FAILURE);
        }

        $runner->io()->writeln(
            'Pushr server: host=' . trim($host) . ', port=' . $port . ', apps=' . count($registry->all()),
            'success',
        );

        $server = new PushrServer($registry, trim($host), $port, $maxSkew);

        $server->run();
    }
}
