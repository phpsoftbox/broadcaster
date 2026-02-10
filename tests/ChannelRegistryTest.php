<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use PhpSoftBox\Broadcaster\Channel\ChannelRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(ChannelRegistry::class)]
final class ChannelRegistryTest extends TestCase
{
    /**
     * Проверяет авторизацию канала по шаблону с переменными.
     */
    #[Test]
    public function testAuthorizeMatchesPattern(): void
    {
        $registry = new ChannelRegistry();

        $registry->channel('private.user.{userId}', function (ServerRequestInterface $request, string $userId): bool {
            return (string) $request->getAttribute('user_id') === $userId;
        });

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn (string $name, mixed $default = null): mixed => $name === 'user_id' ? '10' : $default,
        );
        $result = $registry->authorize('private.user.10', $request);

        $this->assertTrue($result->authorized());
        $this->assertSame(['userId' => '10'], $result->params());
    }

    /**
     * Проверяет отказ при отсутствии подходящего правила.
     */
    #[Test]
    public function testAuthorizeReturnsFalseWhenNoRuleMatches(): void
    {
        $registry = new ChannelRegistry();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn (string $name, mixed $default = null): mixed => $default,
        );
        $result = $registry->authorize('private.user.1', $request);

        $this->assertFalse($result->authorized());
    }
}
