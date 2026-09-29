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

Сервер однопоточный (цикл `stream_select`), поэтому защищён от медленных клиентов: HTTP-handshake дочитывается
неблокирующе, соединение без завершённого handshake закрывается через 5 секунд, заголовки больше 16 КиБ отклоняются.
Кадр с payload больше 1 МиБ или некорректной длиной закрывает соединение.

Поддерживаемые сообщения от клиента:
- `subscribe` — подписка на канал (для приватных каналов нужен `auth`)
- `unsubscribe` — отписка
- `publish` — публикация события; только для соединений публикатора (`role=publisher`, см.
  [Авторизация](03-auth.md#публикация)), остальным — ошибка `Publish is not allowed for this connection`

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
  "channel": "private.user.10",
  "auth": "app-1:signature"
}
```

Для presence-каналов можно передать `channel_data`:

```json
{
  "type": "subscribe",
  "channel": "presence.chat",
  "auth": "app-1:signature",
  "channel_data": {"user_id": 1, "name": "Name"}
}
```
