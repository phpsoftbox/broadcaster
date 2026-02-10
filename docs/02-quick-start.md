# Quick Start

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

Подключение клиента:

```php
use PhpSoftBox\Broadcaster\Pushr\PushrClient;

$client = new PushrClient('127.0.0.1', 8080, 'app-1', 'secret-1');
$client->connect();
$client->subscribe('news');
$client->publish('news', 'message', ['text' => 'hello']);
```
