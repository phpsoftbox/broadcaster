<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use PhpSoftBox\Broadcaster\Contracts\PushrRegistrySourceInterface;
use PhpSoftBox\Broadcaster\Registry\CompositePushrRegistryBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CompositePushrRegistryBuilderTest extends TestCase
{
    /**
     * Объединяет приложения из нескольких источников в единый реестр.
     */
    #[Test]
    public function testBuildMergesSources(): void
    {
        $builder = new CompositePushrRegistryBuilder([
            new class () implements PushrRegistrySourceInterface {
                public function apps(array $options = []): array
                {
                    return ['core' => 'secret-core'];
                }
            },
            new class () implements PushrRegistrySourceInterface {
                public function apps(array $options = []): array
                {
                    return ['tenant-1' => 'secret-tenant-1'];
                }
            },
        ]);

        $registry = $builder->build();

        $this->assertSame([
            'core'     => 'secret-core',
            'tenant-1' => 'secret-tenant-1',
        ], $registry->all());
    }

    /**
     * Выбрасывает исключение при конфликте одинакового app_id с разными secret.
     */
    #[Test]
    public function testBuildThrowsOnAppIdConflict(): void
    {
        $builder = new CompositePushrRegistryBuilder([
            new class () implements PushrRegistrySourceInterface {
                public function apps(array $options = []): array
                {
                    return ['tenant-1' => 'secret-a'];
                }
            },
            new class () implements PushrRegistrySourceInterface {
                public function apps(array $options = []): array
                {
                    return ['tenant-1' => 'secret-b'];
                }
            },
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Конфликт Pushr app_id "tenant-1"');

        $builder->build();
    }

    /**
     * Принимает numeric app_id из ключей массива и приводит его к строке.
     */
    #[Test]
    public function testBuildAcceptsNumericAppIdsFromArrayKeys(): void
    {
        $builder = new CompositePushrRegistryBuilder([
            new class () implements PushrRegistrySourceInterface {
                public function apps(array $options = []): array
                {
                    return [1 => 'secret-tenant-1'];
                }
            },
        ]);

        $registry = $builder->build();

        $this->assertSame('secret-tenant-1', $registry->secret('1'));
    }
}
