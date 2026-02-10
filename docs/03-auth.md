# Авторизация

При подключении к серверу используется подпись `signature`.

Формула:

```
signature = HMAC_SHA256(app_id + ":" + timestamp, secret)
```

Параметры передаются в query:
- `app_id`
- `timestamp`
- `signature`

## Flow

1. Бэкенд хранит `app_id` и `secret`.
2. Клиент запрашивает у бэкенда подпись (например, через HTTP эндпоинт).
3. Бэкенд генерирует `timestamp` и `signature` и возвращает клиенту.
4. Клиент подключается к WebSocket с параметрами `app_id`, `timestamp`, `signature`.
5. Сервер проверяет подпись и принимает соединение.

Подпись используется только на handshake. При переподключении (обновление страницы, потеря соединения) нужно получить новую подпись с новым `timestamp`.

## Timestamp и max_skew

`max_skew` задаёт допустимое расхождение времени между клиентом и сервером (в секундах).
Если `timestamp` выходит за окно `now ± max_skew`, соединение будет отклонено.

Подпись можно сгенерировать через CLI:

```bash
php psb pushr:signature --app-id=app-1 --secret=secret-1
```

Сервер проверяет подпись и допустимое смещение времени (`max_skew`).

## Авторизация каналов

Для приватных каналов требуется отдельная подпись. Каналы с префиксом `private-`/`private:` и `presence-`/`presence:` считаются приватными.

Формула подписи канала:

```
channel_signature = HMAC_SHA256(socket_id + ":" + channel + ":" + channel_data, secret)
```

`channel_data` используется только для presence‑каналов; для private‑каналов он опционален.
Если `channel_data` не передан, подпись считается от строки `socket_id + ":" + channel`.
Если `channel_data` передаётся как объект/массив, он сериализуется в JSON; при подписи и подписке нужно использовать одинаковые данные.

Строка `auth`, которую получает клиент, имеет вид:

```
auth = app_id + ":" + channel_signature
```

### Flow приватного канала

1. Клиент подключается к WebSocket и получает `socket_id` в событии `connection`.
2. Клиент отправляет `socket_id` и `channel` на бэкенд (`/broadcast/auth`).
3. Бэкенд генерирует `auth` и возвращает клиенту.
4. Клиент отправляет `subscribe` с `auth`.

### Пример backend endpoint

```php
use PhpSoftBox\Broadcaster\Channel\ChannelRegistry;
use PhpSoftBox\Broadcaster\Pushr\PushrChannelAuth;

$channels = $container->get(ChannelRegistry::class); // или через DI в обработчике

$socketId = $request->input('socket_id');
$channel = $request->input('channel');
$channelData = $request->input('channel_data'); // optional

$authorization = $channels->authorize($channel, $request);
if (!$authorization->authorized()) {
    return ['status' => 403];
}

$channelData = $authorization->channelData() ?? $channelData;

$auth = PushrChannelAuth::token('app-1', 'secret-1', $socketId, $channel, $channelData);

return ['auth' => $auth, 'channel_data' => $channelData];
```

Правила авторизации удобно хранить в `config/broadcaster/*.php` через `ChannelRegistry` (см. раздел «Каналы и шаблоны»).

### Пример subscribe сообщения

```json
{
  "type": "subscribe",
  "channel": "private-users",
  "auth": "app-1:signature"
}
```

Для presence‑канала:

```json
{
  "type": "subscribe",
  "channel": "presence-chat",
  "auth": "app-1:signature",
  "channel_data": {"user_id": 1, "name": "Anton"}
}
```

### Публикация в приватные каналы

Публикация в `private-*` и `presence-*` каналы тоже требует `auth`.
Обычно этот `auth` генерируется только на бэкенде — фронтенд не должен уметь публиковать напрямую.

Пример:

```json
{
  "type": "publish",
  "channel": "private-users",
  "event": "message",
  "data": {"text": "hello"},
  "auth": "app-1:signature"
}
```

Для генерации `auth` можно использовать CLI:

```bash
php psb pushr:channel-auth --app-id=app-1 --secret=secret-1 --socket-id=socket-123 --channel=private-users
```
