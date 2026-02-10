<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use PhpSoftBox\Broadcaster\Pushr\DeferredPushrPublisher;
use PhpSoftBox\Broadcaster\Pushr\PushrPublication;
use PhpSoftBox\Broadcaster\Tests\Fixtures\RecordingPushrPublicationDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeferredPushrPublisher::class)]
#[CoversClass(PushrPublication::class)]
#[CoversMethod(DeferredPushrPublisher::class, 'publishMany')]
#[CoversMethod(PushrPublication::class, 'toArray')]
#[CoversMethod(PushrPublication::class, 'fromArray')]
final class DeferredPushrPublisherTest extends TestCase
{
    /**
     * Проверяет перенос нейтрального сериализуемого сообщения в предоставленный приложением queue/outbox dispatcher.
     *
     * @see DeferredPushrPublisher::publishMany()
     * @see PushrPublication::toArray()
     * @see PushrPublication::fromArray()
     */
    #[Test]
    public function publishManyDispatchesSerializablePublication(): void
    {
        $dispatcher = new RecordingPushrPublicationDispatcher();

        $publisher = new DeferredPushrPublisher($dispatcher);

        $publisher->publishMany(['news', 'news'], 'updated', ['id' => 7]);

        self::assertCount(1, $dispatcher->publications);
        $payload  = $dispatcher->publications[0]->toArray();
        $restored = PushrPublication::fromArray($payload);
        self::assertSame(['news'], $restored->channels);
        self::assertSame('updated', $restored->event);
        self::assertSame(['id' => 7], $restored->data);
    }
}
