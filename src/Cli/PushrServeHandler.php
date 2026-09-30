<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Cli;

use InvalidArgumentException;
use PhpSoftBox\Broadcaster\Pushr\PushrAppRegistry;
use PhpSoftBox\Broadcaster\Pushr\PushrServer;
use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;

use function is_numeric;
use function is_string;

final class PushrServeHandler implements HandlerInterface
{
    public function run(RunnerInterface $runner): int|Response
    {
        $host         = $runner->request()->option('host', '0.0.0.0');
        $port         = $runner->request()->option('port', 8080);
        $appId        = $runner->request()->option('app-id');
        $secret       = $runner->request()->option('secret');
        $maxSkew      = $runner->request()->option('max-skew', 300);
        $pingInterval = $runner->request()->option('ping-interval', 25);
        $idleTimeout  = $runner->request()->option('idle-timeout', 60);

        if (!is_string($host) || $host === '') {
            $runner->io()->writeln('Некорректный host.', 'error');

            return Response::FAILURE;
        }

        if (!is_numeric($port)) {
            $runner->io()->writeln('Некорректный port.', 'error');

            return Response::FAILURE;
        }

        if (!is_string($appId) || $appId === '' || !is_string($secret) || $secret === '') {
            $runner->io()->writeln('app-id и secret обязательны.', 'error');

            return Response::FAILURE;
        }

        if (!is_numeric($pingInterval) || !is_numeric($idleTimeout)) {
            $runner->io()->writeln('Некорректные ping-interval или idle-timeout.', 'error');

            return Response::FAILURE;
        }

        $registry = new PushrAppRegistry([$appId => $secret]);

        try {
            $server = new PushrServer(
                $registry,
                $host,
                (int) $port,
                (int) $maxSkew,
                pingInterval: (int) $pingInterval,
                idleTimeout: (int) $idleTimeout,
            );
        } catch (InvalidArgumentException $exception) {
            $runner->io()->writeln($exception->getMessage(), 'error');

            return Response::FAILURE;
        }

        $runner->io()->writeln('Pushr сервер запущен на ' . $host . ':' . $port, 'success');

        $server->run();

        return Response::SUCCESS;
    }
}
