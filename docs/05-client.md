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

## Короткоживущая публикация из приложения

`PushrPublisher` предназначен для случаев, когда приложение открывает соединение,
отправляет событие и сразу его закрывает. Четыре независимых таймаута ограничивают
ожидание подключения, WebSocket-handshake, получения `socket_id` и записи в сокет:

```php
use PhpSoftBox\Broadcaster\Pushr\PushrPublisher;
use PhpSoftBox\Broadcaster\Pushr\PushrPublisherOptions;

$publisher = new PushrPublisher(
    appId: 'app-1',
    secret: 'secret-1',
    host: '127.0.0.1',
    port: 8080,
    options: new PushrPublisherOptions(
        connectTimeoutSeconds: 0.5,
        handshakeTimeoutSeconds: 0.5,
        readTimeoutSeconds: 0.5,
        writeTimeoutSeconds: 0.5,
    ),
);
```

По умолчанию каждый таймаут равен одной секунде. Ошибка подключения или отправки
выбрасывается как исключение. Компонент намеренно не решает, можно ли проигнорировать
эту ошибку после успешной бизнес-операции: такую fail-open/fail-closed политику
задаёт вызывающий код.

Одно событие можно отправить сразу в несколько каналов:

```php
$publisher->publishMany(
    ['news', 'private.user.10', 'presence.orders'],
    'order.updated',
    ['id' => 42],
);
```

Для всего списка используется одно подключение и один handshake. `socket_id`
ожидается только при наличии `private.*` или `presence.*` канала; публичная
публикация не тратит время на чтение приветственного сообщения.

## Очередь и transactional outbox

Broadcaster не зависит от конкретной очереди или базы данных. Адаптер приложения
реализует один узкий интерфейс и сохраняет переданный объект в выбранный транспорт:

```php
use PhpSoftBox\Broadcaster\Contracts\PushrPublicationDispatcherInterface;
use PhpSoftBox\Broadcaster\Pushr\DeferredPushrPublisher;
use PhpSoftBox\Broadcaster\Pushr\PushrPublication;

final class OutboxPushrDispatcher implements PushrPublicationDispatcherInterface
{
    public function dispatch(PushrPublication $publication): void
    {
        $this->outbox->append('pushr.publish', $publication->toArray());
    }
}

$publisher = new DeferredPushrPublisher(new OutboxPushrDispatcher($outbox));
$publisher->publishMany(['news', 'private.user.10'], 'order.updated', ['id' => 42]);
```

Consumer восстанавливает DTO через `PushrPublication::fromArray()` и передаёт его
каналы, событие и данные обычному `PushrPublisher`. Transactional outbox имеет
смысл, когда realtime-событие нельзя потерять после фиксации основной транзакции.
Обычная очередь подходит, когда достаточно асинхронной доставки без общей
транзакции с бизнес-данными.

## Подписка на приватные каналы

Подпись для приватного канала должна быть сгенерирована на бэкенде:

```php
use PhpSoftBox\Broadcaster\Pushr\PushrChannelAuth;

$socketId = $client->receive(1)['socket_id'] ?? null; // из события connection
$auth = PushrChannelAuth::token('app-1', 'secret-1', $socketId, 'private.user.10');
$client->subscribe('private.user.10', $auth);
```

Presence-каналы подписываются с `channel_data`:

```php
$channelData = ['user_id' => 10, 'name' => 'John'];
$auth = PushrChannelAuth::token('app-1', 'secret-1', $socketId, 'presence.chat', $channelData);
$client->subscribe('presence.chat', $auth, $channelData);
```

## Публикация в приватные каналы

Публикация в приватные каналы требует `auth`:

```php
$auth = PushrChannelAuth::token('app-1', 'secret-1', $socketId, 'private.user.10');
$client->publish('private.user.10', 'message', ['text' => 'hello'], $auth);
```
