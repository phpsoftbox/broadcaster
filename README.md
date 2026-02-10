# PhpSoftBox Broadcaster (Pushr)

## About
`phpsoftbox/broadcaster` — компонент для запуска WebSocket сервера и обмена сообщениями между сервисами. Драйвер `Pushr` реализует собственный протокол с авторизацией по `app_id` и `signature`.

Ключевые свойства:
- сервер `PushrServer` (WebSocket)
- клиент `PushrClient` для публикации/подписки
- подписи `PushrSignature`
- подписи каналов `PushrChannelAuth`
- реестр каналов `ChannelRegistry`
- поддержка каналов (rooms), включая приватные

## Quick Start
Запуск сервера:

```php
use PhpSoftBox\Broadcaster\Pushr\PushrAppRegistry;
use PhpSoftBox\Broadcaster\Pushr\PushrServer;

$registry = new PushrAppRegistry([
    'app-1' => 'secret-1',
]);

$server = new PushrServer($registry, host: '0.0.0.0', port: 8080);
$server->run();
```

Подключение клиентом:

```php
use PhpSoftBox\Broadcaster\Pushr\PushrClient;

$client = new PushrClient('127.0.0.1', 8080, 'app-1', 'secret-1');
$client->connect();
$client->subscribe('news');
$client->publish('news', 'message', ['text' => 'hello']);
```

Публикация из PHP-кода без постоянного подключения:

```php
use PhpSoftBox\Broadcaster\Pushr\PushrPublisher;

$publisher = new PushrPublisher('app-1', 'secret-1', '127.0.0.1', 8080);
$publisher->publish('news', 'message', ['text' => 'hello']);
```

## Оглавление
- [Документация](docs/index.md)
- [About](docs/01-about.md)
- [Quick Start](docs/02-quick-start.md)
- [Авторизация](docs/03-auth.md)
- [Server](docs/04-server.md)
- [Client](docs/05-client.md)
- [CLI](docs/06-cli.md)
- [DI](docs/07-di.md)
- [Каналы и шаблоны](docs/08-channels.md)
