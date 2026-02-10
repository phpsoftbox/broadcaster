<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use PhpSoftBox\Broadcaster\Registry\ConfigPushrRegistrySource;
use PhpSoftBox\Broadcaster\Tests\Fixtures\TestEnvironment;
use PhpSoftBox\Config\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigPushrRegistrySource::class)]
#[CoversMethod(ConfigPushrRegistrySource::class, 'apps')]
final class ConfigPushrRegistrySourceTest extends TestCase
{
    /**
     * Проверяет объединение зарегистрированных приложений с учётными данными приложения по умолчанию.
     *
     * @see ConfigPushrRegistrySource::apps()
     */
    #[Test]
    public function testReadsAppsFromConfigAndDefaultCredentials(): void
    {
        $config = new Config(
            TestEnvironment::Test,
            [[
                'pushr' => [
                    'apps' => [
                        'tenant-1' => 'tenant-secret-1',
                    ],
                    'app_id' => 'default-app',
                    'secret' => 'default-secret',
                ],
            ]],
        );

        $source = new ConfigPushrRegistrySource($config);

        $this->assertSame([
            'tenant-1'    => 'tenant-secret-1',
            'default-app' => 'default-secret',
        ], $source->apps());
    }

    /**
     * Проверяет, что опция without-default-app оставляет только явно зарегистрированные приложения.
     *
     * @see ConfigPushrRegistrySource::apps()
     */
    #[Test]
    public function testWithoutDefaultAppSkipsDefaultCredentials(): void
    {
        $config = new Config(
            TestEnvironment::Test,
            [[
                'pushr' => [
                    'apps' => [
                        ['app_id' => 'tenant-1', 'secret' => 'tenant-secret-1'],
                    ],
                    'app_id' => 'default-app',
                    'secret' => 'default-secret',
                ],
            ]],
        );

        $source = new ConfigPushrRegistrySource($config);

        $this->assertSame([
            'tenant-1' => 'tenant-secret-1',
        ], $source->apps(['without-default-app' => true]));
    }
}
