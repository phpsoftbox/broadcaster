# Server

`PushrServer` запускает WebSocket сервер и принимает подключения.

```php
$server = new PushrServer(
    apps: new PushrAppRegistry(['app-1' => 'secret-1']),
    host: '0.0.0.0',
    port: 8080,
    maxSkew: 300,
);

$server->run();
```

Поддерживаемые сообщения от клиента:
- `subscribe` — подписка на канал (для приватных каналов нужен `auth`)
- `unsubscribe` — отписка
- `publish` — публикация события (в приватные каналы нужен `auth`)

Формат:

```json
{
  "type": "publish",
  "channel": "news",
  "event": "message",
  "data": {"text": "hello"}
}
```

## Подписка на приватный канал

```json
{
  "type": "subscribe",
  "channel": "private-users",
  "auth": "app-1:signature"
}
```

Для presence-каналов можно передать `channel_data`:

```json
{
  "type": "subscribe",
  "channel": "presence-chat",
  "auth": "app-1:signature",
  "channel_data": {"user_id": 1, "name": "Name"}
}
```
