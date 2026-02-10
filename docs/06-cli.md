# CLI

Команды автоматически регистрируются через `extra.psb.providers`.

Доступно:
- `pushr:signature` — сгенерировать подпись
- `pushr:channel-auth` — сгенерировать auth для приватного канала
- `pushr:serve` — запустить сервер

Примеры:

```bash
php psb pushr:signature --app-id=app-1 --secret=secret-1
php psb pushr:channel-auth --app-id=app-1 --secret=secret-1 --socket-id=socket-123 --channel=private-users
php psb pushr:serve --host=0.0.0.0 --port=8080 --app-id=app-1 --secret=secret-1
```
