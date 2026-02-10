<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Cli;

use PhpSoftBox\Broadcaster\Pushr\PushrChannelAuth;
use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;

use function is_string;
use function json_decode;
use function json_encode;
use function json_last_error;

use const JSON_ERROR_NONE;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class PushrChannelAuthHandler implements HandlerInterface
{
    public function run(RunnerInterface $runner): int|Response
    {
        $appId          = $runner->request()->option('app-id');
        $secret         = $runner->request()->option('secret');
        $socketId       = $runner->request()->option('socket-id');
        $channel        = $runner->request()->option('channel');
        $rawChannelData = $runner->request()->option('channel-data');

        if (!is_string($appId) || $appId === '' || !is_string($secret) || $secret === '') {
            $runner->io()->writeln('app-id и secret обязательны.', 'error');

            return Response::FAILURE;
        }

        if (!is_string($socketId) || $socketId === '' || !is_string($channel) || $channel === '') {
            $runner->io()->writeln('socket-id и channel обязательны.', 'error');

            return Response::FAILURE;
        }

        $channelData = $this->decodeChannelData($rawChannelData);
        $auth        = PushrChannelAuth::token($appId, $secret, $socketId, $channel, $channelData);

        $runner->io()->writeln('auth: ' . $auth, 'success');
        if ($channelData !== null) {
            $runner->io()->writeln('channel_data: ' . $this->stringifyChannelData($channelData), 'info');
        }

        return Response::SUCCESS;
    }

    private function decodeChannelData(mixed $data): mixed
    {
        if (!is_string($data) || $data === '') {
            return null;
        }

        $decoded = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $data;
    }

    private function stringifyChannelData(mixed $data): string
    {
        if (!is_string($data)) {
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $encoded === false ? '' : $encoded;
        }

        return $data;
    }
}
