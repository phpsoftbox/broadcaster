<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use PhpSoftBox\Broadcaster\Contracts\PushrClientInterface;
use PhpSoftBox\Broadcaster\Pushr\PushrPublisher;
use PhpSoftBox\Broadcaster\Pushr\PushrPublisherOptions;
use PhpSoftBox\Broadcaster\Tests\Fixtures\FakePushrClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_column;

#[CoversClass(PushrPublisher::class)]
#[CoversMethod(PushrPublisher::class, 'publish')]
#[CoversMethod(PushrPublisher::class, 'publishMany')]
final class PushrPublisherTest extends TestCase
{
    /**
     * Проверяет, что публикация в несколько каналов использует одно соединение и не ждёт socket_id:
     * соединение публикатора не нуждается в auth каналов.
     *
     * @see PushrPublisher::publishMany()
     */
    #[Test]
    public function publishManyReusesConnectionWithoutChannelAuth(): void
    {
        $client = new FakePushrClient();

        $publisher = $this->publisherUsing($client);

        $publisher->publishMany(
            ['news', 'private.account.7', 'presence.room.3'],
            'updated',
            ['id' => 7],
        );

        self::assertSame(1, $client->connectCalls);
        self::assertSame(0, $client->receiveCalls);
        self::assertSame(1, $client->closeCalls);
        self::assertSame(
            ['news', 'private.account.7', 'presence.room.3'],
            array_column($client->publications, 'channel'),
        );
    }

    /**
     * Проверяет, что соединение закрывается, если отправка одного из сообщений завершилась исключением.
     *
     * @see PushrPublisher::publishMany()
     */
    #[Test]
    public function publishManyClosesConnectionAfterFailure(): void
    {
        $client = new FakePushrClient();

        $client->failOnChannel = 'broken';
        $publisher             = $this->publisherUsing($client);

        try {
            $publisher->publishMany(['news', 'broken'], 'updated');
            self::fail('Publication failure was expected.');
        } catch (RuntimeException $exception) {
            self::assertSame('Test publication failure.', $exception->getMessage());
        }

        self::assertSame(1, $client->closeCalls);
    }

    private function publisherUsing(FakePushrClient $client): PushrPublisher
    {
        return new PushrPublisher(
            appId: 'app-1',
            secret: 'secret-1',
            host: '127.0.0.1',
            options: new PushrPublisherOptions(),
            clientFactory: static fn (
                string $host,
                int $port,
                string $appId,
                string $secret,
                string $path,
                PushrPublisherOptions $options,
            ): PushrClientInterface => $client,
        );
    }
}
