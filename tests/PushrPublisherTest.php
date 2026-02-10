<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use PhpSoftBox\Broadcaster\Contracts\PushrClientInterface;
use PhpSoftBox\Broadcaster\Pushr\PushrChannelAuth;
use PhpSoftBox\Broadcaster\Pushr\PushrPublisher;
use PhpSoftBox\Broadcaster\Pushr\PushrPublisherOptions;
use PhpSoftBox\Broadcaster\Tests\Fixtures\FakePushrClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(PushrPublisher::class)]
#[CoversMethod(PushrPublisher::class, 'publish')]
#[CoversMethod(PushrPublisher::class, 'publishMany')]
final class PushrPublisherTest extends TestCase
{
    /**
     * Проверяет, что публикация в несколько каналов использует одно соединение и авторизует только закрытые каналы.
     *
     * @see PushrPublisher::publishMany()
     * @see PushrChannelAuth::verify()
     */
    #[Test]
    public function publishManyReusesConnectionAndAuthorizesPrivateChannels(): void
    {
        $client = new FakePushrClient();

        $client->receivedMessages = [['socket_id' => 'socket-42']];
        $publisher                = $this->publisherUsing($client);

        $publisher->publishMany(
            ['news', 'private.account.7', 'presence.room.3'],
            'updated',
            ['id' => 7],
        );

        self::assertSame(1, $client->connectCalls);
        self::assertSame(1, $client->receiveCalls);
        self::assertSame(1, $client->closeCalls);
        self::assertCount(3, $client->publications);
        self::assertNull($client->publications[0]['auth']);
        self::assertTrue(PushrChannelAuth::verify(
            'app-1',
            'secret-1',
            'socket-42',
            'private.account.7',
            (string) $client->publications[1]['auth'],
        ));
        self::assertTrue(PushrChannelAuth::verify(
            'app-1',
            'secret-1',
            'socket-42',
            'presence.room.3',
            (string) $client->publications[2]['auth'],
        ));
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
            options: new PushrPublisherOptions(readTimeoutSeconds: 0.1),
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
