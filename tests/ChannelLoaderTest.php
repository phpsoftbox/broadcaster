<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use PhpSoftBox\Broadcaster\Channel\ChannelLoader;
use PhpSoftBox\Broadcaster\Channel\ChannelRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

use function file_put_contents;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(ChannelLoader::class)]
final class ChannelLoaderTest extends TestCase
{
    /**
     * Проверяет загрузку правил и передачу контейнера в конфиг.
     */
    #[Test]
    public function testLoadPassesContainerToDefinition(): void
    {
        $dir = sys_get_temp_dir() . '/psb-broadcaster-' . uniqid('', true);
        mkdir($dir);

        $fileA = $dir . '/a.php';
        $fileB = $dir . '/b.php';

        file_put_contents($fileA, <<<'PHP'
<?php
use PhpSoftBox\Broadcaster\Channel\ChannelRegistry;

return static function (ChannelRegistry $channels): void {
    $channels->channel('public.news', static fn (): bool => true);
};
PHP);

        file_put_contents($fileB, <<<'PHP'
<?php
use PhpSoftBox\Broadcaster\Channel\ChannelRegistry;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

return static function (ChannelRegistry $channels, ContainerInterface $container): void {
    $channels->channel('private.user.{userId}', static function (ServerRequestInterface $request, string $userId) use ($container): bool {
        return $container->get('user_id') === $userId;
    });
};
PHP);

        $container = new class () implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id !== 'user_id') {
                    throw new RuntimeException('Unknown id: ' . $id);
                }

                return '42';
            }

            public function has(string $id): bool
            {
                return $id === 'user_id';
            }
        };

        $registry = new ChannelRegistry();

        new ChannelLoader($dir, $container)->load($registry);

        $this->assertCount(2, $registry->rules());

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn (string $name, mixed $default = null): mixed => $default,
        );
        $result = $registry->authorize('private.user.42', $request);

        $this->assertTrue($result->authorized());

        unlink($fileA);
        unlink($fileB);
        rmdir($dir);
    }
}
