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

        $pingInterval = $runner->request()->option('ping-interval', 25);
        if (!is_int($pingInterval) || $pingInterval < 0) {
            throw new DaemonStartupException('Некорректный параметр --ping-interval.', Response::INVALID_INPUT);
        }

        $idleTimeout = $runner->request()->option('idle-timeout', 60);
        if (!is_int($idleTimeout) || $idleTimeout < 0) {
            throw new DaemonStartupException('Некорректный параметр --idle-timeout.', Response::INVALID_INPUT);
        }

        if ($pingInterval > 0 && $idleTimeout > 0 && $idleTimeout <= $pingInterval) {
            throw new DaemonStartupException(
                'Параметр --idle-timeout должен быть больше --ping-interval.',
                Response::INVALID_INPUT,
            );
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

        $server = new PushrServer(
            $registry,
            trim($host),
            $port,
            $maxSkew,
            pingInterval: $pingInterval,
            idleTimeout: $idleTimeout,
        );

        $server->run();
    }
}
