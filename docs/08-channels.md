# Каналы и шаблоны

В приложении можно описывать правила авторизации каналов в файлах `config/broadcaster/*.php`.

Пример `app/local/backend/config/broadcaster/app.php`:

```php
use PhpSoftBox\Broadcaster\Channel\ChannelRegistry;
use PhpSoftBox\Users\TeamAccessChecker;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

return static function (ChannelRegistry $channels, ContainerInterface $container): void {
    $teamAccess = $container->get(TeamAccessChecker::class);

    $channels->channel('private.user.{userId}', function (ServerRequestInterface $request, string $userId): bool {
        $currentUserId = (string) ($request->getAttribute('user_id') ?? '');

        return $currentUserId !== '' && $currentUserId === $userId;
    });

    $channels->channel('presence.team.{teamId}', function (ServerRequestInterface $request, string $teamId) use ($teamAccess): array|bool {
        $userId = (string) ($request->getAttribute('user_id') ?? '');

        if ($userId === '' || !$teamAccess->canJoin($userId, $teamId)) {
            return false;
        }

        return ['user_id' => $userId, 'team_id' => $teamId];
    });
};
```

## Как работают переменные

Шаблон канала может содержать переменные в фигурных скобках:

```
private.user.{userId}
```

При авторизации строки канала переменные извлекаются по порядку и передаются в обработчик:

```php
$channels->channel('private.user.{userId}', function (ServerRequestInterface $request, string $userId): bool {
    // ...
});
```

Если нужен доступ к сервисам приложения, добавьте вторым аргументом `ContainerInterface` и получите нужные зависимости:

```php
use PhpSoftBox\Broadcaster\Channel\ChannelRegistry;
use PhpSoftBox\Users\TeamAccessChecker;
use Psr\Container\ContainerInterface;

return static function (ChannelRegistry $channels, ContainerInterface $container): void {
    $teamAccess = $container->get(TeamAccessChecker::class);
    // ...
};
```

## Возвращаемое значение

- `true` — доступ разрешён;
- `false`/`null` — доступ запрещён;
- массив — доступ разрешён, массив будет использован как `channel_data` (для presence-каналов).
