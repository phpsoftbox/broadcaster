# CLI

Команды автоматически регистрируются через `extra.psb.providers`.

Доступно:
- `pushr:signature` — сгенерировать подпись
- `pushr:channel-auth` — сгенерировать auth для приватного канала
- `pushr:serve` — запустить сервер
- `pushr:serve:registry` — запустить сервер на реестре приложений (через `PushrRegistryBuilderInterface`)

Примеры:

```bash
php psb pushr:signature --app-id=app-1 --secret=secret-1
php psb pushr:channel-auth --app-id=app-1 --secret=secret-1 --socket-id=socket-123 --channel=private.user.10
php psb pushr:serve --host=0.0.0.0 --port=8080 --app-id=app-1 --secret=secret-1
php psb pushr:serve:registry --host=0.0.0.0 --port=8080
php psb pushr:serve:registry --host=0.0.0.0 --port=8080 --without-default-app
```

`pushr:serve:registry` собирает приложения из настроенных источников реестра. Стандартный
`ConfigPushrRegistrySource` читает список `pushr.apps` и, если не указан
`--without-default-app`, добавляет отдельное приложение из `pushr.app_id` / `pushr.secret`.

Tenant-aware запуск находится в пакете `phpsoftbox/multi-tenant`:
`tenant:pushr:serve:registry --tenant=all`.
