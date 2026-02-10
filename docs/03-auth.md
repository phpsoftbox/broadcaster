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

Для приватных каналов требуется отдельная подпись.
Приватными считаются каналы с префиксом `private.` и `presence.`.

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

### private и presence

- `private.*` — канал с проверкой доступа, без обязательных данных участника.
- `presence.*` — канал с проверкой доступа и данными участника (`channel_data`, обычно `user_id` и отображаемое имя).
- В текущем Pushr `presence` не публикует автоматически события join/leave; `channel_data` используется для подписи и передачи метаданных подписки.

### Flow приватного канала

1. Клиент подключается к WebSocket и получает `socket_id` в событии `connection`.
2. Клиент отправляет `socket_id` и `channel` на бэкенд (`/broadcast/auth`).
3. Бэкенд генерирует `auth` и возвращает клиенту.
4. Клиент отправляет `subscribe` с `auth`.

### Пример backend endpoint

Рекомендуется использовать `PushrHttpEndpoints` из пакета, чтобы не дублировать одинаковую логику между проектами.

```php
use PhpSoftBox\Application\Response\JsonResponse;
use PhpSoftBox\Broadcaster\Http\PushrHttpEndpoints;

$result = $endpoints->auth($request->body(), $request->psr());

return new JsonResponse($result->payload(), $result->status());
```

Правила авторизации удобно хранить в `config/broadcaster/*.php` через `ChannelRegistry` (см. раздел «Каналы и шаблоны»).  
Дополнительные проектные проверки (например `resolveChannelArea`/prefix-check) делайте в своём контроллере до вызова `auth()`.

### Пример subscribe сообщения

```json
{
  "type": "subscribe",
  "channel": "private.user.10",
  "auth": "app-1:signature"
}
```

Для presence‑канала:

```json
{
  "type": "subscribe",
  "channel": "presence.chat",
  "auth": "app-1:signature",
  "channel_data": {"user_id": 1, "name": "Anton"}
}
```

### Публикация в приватные каналы

Публикация в `private.*` и `presence.*` каналы тоже требует `auth`.
Обычно этот `auth` генерируется только на бэкенде — фронтенд не должен уметь публиковать напрямую.

Пример:

```json
{
  "type": "publish",
  "channel": "private.user.10",
  "event": "message",
  "data": {"text": "hello"},
  "auth": "app-1:signature"
}
```

В PHP можно использовать channel-обёртки, чтобы не ошибаться с префиксом:

```php
use PhpSoftBox\Broadcaster\Channel\PrivateChannel;

$publisher->publish(new PrivateChannel('admin.user.10'), 'phone.confirmed', ['user_id' => 10]);
```

Для генерации `auth` можно использовать CLI:

```bash
php psb pushr:channel-auth --app-id=app-1 --secret=secret-1 --socket-id=socket-123 --channel=private.user.10
```
