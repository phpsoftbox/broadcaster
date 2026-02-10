<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Cli;

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
        $host    = $runner->request()->option('host', '0.0.0.0');
        $port    = $runner->request()->option('port', 8080);
        $appId   = $runner->request()->option('app-id');
        $secret  = $runner->request()->option('secret');
        $maxSkew = $runner->request()->option('max-skew', 300);

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

        $registry = new PushrAppRegistry([$appId => $secret]);

        $server = new PushrServer($registry, $host, (int) $port, (int) $maxSkew);

        $runner->io()->writeln('Pushr сервер запущен на ' . $host . ':' . $port, 'success');

        $server->run();

        return Response::SUCCESS;
    }
}
