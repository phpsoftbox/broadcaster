# About

`phpsoftbox/broadcaster` предоставляет WebSocket сервер и клиент для обмена событиями. Драйвер `Pushr` работает как микросервис: клиенты подключаются по WebSocket, подписываются на каналы и получают события.

Основные элементы:
- `PushrServer` — сервер WebSocket
- `PushrClient` — клиент для подписок и публикации
- `PushrSignature` — генерация и проверка подписи
- `PushrChannelAuth` — подписи для приватных каналов
- `PushrAppRegistry` — список приложений (app_id → secret)
