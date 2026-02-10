<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Cli;

use PhpSoftBox\CliApp\Command\Command;
use PhpSoftBox\CliApp\Command\CommandRegistryInterface;
use PhpSoftBox\CliApp\Command\OptionDefinition;
use PhpSoftBox\CliApp\Loader\CommandProviderInterface;

final class PushrCommandProvider implements CommandProviderInterface
{
    public function register(CommandRegistryInterface $registry): void
    {
        $registry->register(Command::define(
            name: 'pushr:signature',
            description: 'Сгенерировать signature для подключения к Pushr',
            signature: [
                new OptionDefinition(
                    name: 'app-id',
                    short: 'a',
                    description: 'App ID',
                    required: true,
                    type: 'string',
                ),
                new OptionDefinition(
                    name: 'secret',
                    short: 's',
                    description: 'App secret',
                    required: true,
                    type: 'string',
                ),
                new OptionDefinition(
                    name: 'timestamp',
                    short: 't',
                    description: 'Unix timestamp (по умолчанию текущее время)',
                    required: false,
                    default: null,
                    type: 'int',
                ),
            ],
            handler: PushrSignatureHandler::class,
        ));

        $registry->register(Command::define(
            name: 'pushr:channel-auth',
            description: 'Сгенерировать auth для приватного канала',
            signature: [
                new OptionDefinition(
                    name: 'app-id',
                    short: 'a',
                    description: 'App ID',
                    required: true,
                    type: 'string',
                ),
                new OptionDefinition(
                    name: 'secret',
                    short: 's',
                    description: 'App secret',
                    required: true,
                    type: 'string',
                ),
                new OptionDefinition(
                    name: 'socket-id',
                    short: 'i',
                    description: 'Socket ID',
                    required: true,
                    type: 'string',
                ),
                new OptionDefinition(
                    name: 'channel',
                    short: 'c',
                    description: 'Имя канала',
                    required: true,
                    type: 'string',
                ),
                new OptionDefinition(
                    name: 'channel-data',
                    short: 'd',
                    description: 'channel_data для presence-каналов (JSON или строка)',
                    required: false,
                    default: null,
                    type: 'string',
                ),
            ],
            handler: PushrChannelAuthHandler::class,
        ));

        $registry->register(Command::define(
            name: 'pushr:serve',
            description: 'Запустить Pushr WebSocket сервер',
            signature: [
                new OptionDefinition(
                    name: 'host',
                    short: 'H',
                    description: 'Хост',
                    required: false,
                    default: '0.0.0.0',
                    type: 'string',
                ),
                new OptionDefinition(
                    name: 'port',
                    short: 'p',
                    description: 'Порт',
                    required: false,
                    default: 8080,
                    type: 'int',
                ),
                new OptionDefinition(
                    name: 'app-id',
                    short: 'a',
                    description: 'App ID',
                    required: true,
                    type: 'string',
                ),
                new OptionDefinition(
                    name: 'secret',
                    short: 's',
                    description: 'App secret',
                    required: true,
                    type: 'string',
                ),
                new OptionDefinition(
                    name: 'max-skew',
                    short: 'k',
                    description: 'Допустимое смещение времени (секунды)',
                    required: false,
                    default: 300,
                    type: 'int',
                ),
            ],
            handler: PushrServeHandler::class,
        ));
    }
}
