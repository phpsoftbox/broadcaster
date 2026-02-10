# HTTP Endpoints

`PushrHttpEndpoints` выносит общую логику для `/broadcast/connect` и `/broadcast/auth`:
- проверка конфигурации (`appId`, `secret`)
- генерация `signature` для handshake
- генерация `auth` для приватных каналов
- нормализация `pushr.public_url` (`http -> ws`, `https -> wss`)

```php
use PhpSoftBox\Application\Response\JsonResponse;
use PhpSoftBox\Broadcaster\Http\PushrHttpEndpoints;
use PhpSoftBox\Request\Request;
use Psr\Http\Message\ResponseInterface;

final readonly class BroadcastController
{
    public function __construct(
        private PushrHttpEndpoints $endpoints,
    ) {
    }

    public function connect(Request $request): ResponseInterface
    {
        $result = $this->endpoints->connect((string) $request->psr()->getUri()->getScheme());

        return new JsonResponse($result->payload(), $result->status());
    }

    public function auth(Request $request): ResponseInterface
    {
        $result = $this->endpoints->auth($request->body(), $request->psr());

        return new JsonResponse($result->payload(), $result->status());
    }
}
```

Проектные проверки (например, area/prefix каналов по host/path) остаются в вашем `BroadcastController` и выполняются до вызова `auth()`.

## Важно: жизненный цикл сервиса

Если `appId/secret/publicUrl` у вас статичны (обычный production), `PushrHttpEndpoints` можно безопасно держать как singleton в DI.

Если конфиг меняется во время выполнения (например, в интеграционных тестах через `Config::set()`), singleton может держать устаревшие значения.  
В этом случае создавайте `PushrHttpEndpoints` на запрос из текущего `Config`:

```php
use PhpSoftBox\Broadcaster\Channel\ChannelRegistry;
use PhpSoftBox\Broadcaster\Http\PushrHttpEndpoints;
use PhpSoftBox\Config\Config;

private function endpoints(ChannelRegistry $channels, Config $config): PushrHttpEndpoints
{
    return new PushrHttpEndpoints(
        channels: $channels,
        appId: (string) ($config->get('pushr.app_id') ?? ''),
        secret: (string) ($config->get('pushr.secret') ?? ''),
        publicUrl: (string) ($config->get('pushr.public_url') ?? ''),
    );
}
```
