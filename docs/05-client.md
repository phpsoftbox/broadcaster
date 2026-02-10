# Client

`PushrClient` подключается к серверу и умеет подписываться/публиковать события.

```php
$client = new PushrClient('127.0.0.1', 8080, 'app-1', 'secret-1');
$client->connect();

$client->subscribe('news');
$client->publish('news', 'message', ['text' => 'hello']);

$event = $client->receive(1);
```

`receive()` возвращает массив события или `null`, если данных нет.

## Подписка на приватные каналы

Подпись для приватного канала должна быть сгенерирована на бэкенде:

```php
use PhpSoftBox\Broadcaster\Pushr\PushrChannelAuth;

$socketId = $client->receive(1)['socket_id'] ?? null; // из события connection
$auth = PushrChannelAuth::token('app-1', 'secret-1', $socketId, 'private-users');
$client->subscribe('private-users', $auth);
```

Presence-каналы подписываются с `channel_data`:

```php
$channelData = ['user_id' => 10, 'name' => 'Anton'];
$auth = PushrChannelAuth::token('app-1', 'secret-1', $socketId, 'presence-chat', $channelData);
$client->subscribe('presence-chat', $auth, $channelData);
```

## Публикация в приватные каналы

Публикация в приватные каналы требует `auth`:

```php
$auth = PushrChannelAuth::token('app-1', 'secret-1', $socketId, 'private-users');
$client->publish('private-users', 'message', ['text' => 'hello'], $auth);
```
