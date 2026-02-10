<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Cli;

use PhpSoftBox\Broadcaster\Pushr\PushrSignature;
use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;

use function is_numeric;
use function is_string;
use function time;

final class PushrSignatureHandler implements HandlerInterface
{
    public function run(RunnerInterface $runner): int|Response
    {
        $appId     = $runner->request()->option('app-id');
        $secret    = $runner->request()->option('secret');
        $timestamp = $runner->request()->option('timestamp');

        if (!is_string($appId) || $appId === '' || !is_string($secret) || $secret === '') {
            $runner->io()->writeln('app-id и secret обязательны.', 'error');

            return Response::FAILURE;
        }

        $ts        = is_numeric($timestamp) ? (int) $timestamp : time();
        $signature = PushrSignature::generate($appId, $secret, $ts);

        $runner->io()->writeln('timestamp: ' . $ts, 'info');
        $runner->io()->writeln('signature: ' . $signature, 'success');

        return Response::SUCCESS;
    }
}
