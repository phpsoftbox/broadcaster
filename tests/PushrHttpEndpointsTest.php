<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Tests;

use PhpSoftBox\Broadcaster\Channel\ChannelRegistry;
use PhpSoftBox\Broadcaster\Http\BroadcastResponse;
use PhpSoftBox\Broadcaster\Http\PushrHttpEndpoints;
use PhpSoftBox\Broadcaster\Pushr\PushrChannelAuth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(PushrHttpEndpoints::class)]
#[CoversClass(BroadcastResponse::class)]
final class PushrHttpEndpointsTest extends TestCase
{
    #[Test]
    public function testConnectReturnsErrorWhenCredentialsAreMissing(): void
    {
        $service = new PushrHttpEndpoints(new ChannelRegistry(), '', '');

        $result = $service->connect('https');

        $this->assertSame(500, $result->status());
        $this->assertSame(['error' => 'Pushr is not configured.'], $result->payload());
    }

    #[Test]
    public function testConnectBuildsPayloadAndNormalizesUrl(): void
    {
        $service = new PushrHttpEndpoints(
            channels: new ChannelRegistry(),
            appId: 'app-1',
            secret: 'secret-1',
            publicUrl: 'https://ws.example.test/socket',
        );

        $result  = $service->connect('https');
        $payload = $result->payload();

        $this->assertSame(200, $result->status());
        $this->assertSame('app-1', $payload['appId']);
        $this->assertSame('wss://ws.example.test/socket', $payload['url']);
        $this->assertIsInt($payload['timestamp']);
        $this->assertIsString($payload['signature']);
        $this->assertNotSame('', $payload['signature']);
    }

    #[Test]
    public function testAuthReturnsValidationErrorWhenBodyIsInvalid(): void
    {
        $service = new PushrHttpEndpoints(new ChannelRegistry(), 'app-1', 'secret-1');

        $request = $this->createMock(ServerRequestInterface::class);
        $result  = $service->auth([], $request);

        $this->assertSame(422, $result->status());
        $this->assertSame(['error' => 'socket_id and channel are required.'], $result->payload());
    }

    #[Test]
    public function testAuthReturnsForbiddenWhenChannelAuthorizationFails(): void
    {
        $registry = new ChannelRegistry();

        $registry->channel('private.user.{userId}', static fn (): bool => false);

        $service = new PushrHttpEndpoints($registry, 'app-1', 'secret-1');
        $request = $this->createMock(ServerRequestInterface::class);
        $result  = $service->auth([
            'socket_id' => 'socket-1',
            'channel'   => 'private.user.10',
        ], $request);

        $this->assertSame(403, $result->status());
        $this->assertSame(['error' => 'Unauthorized.'], $result->payload());
    }

    #[Test]
    public function testAuthReturnsSignedPayloadForAuthorizedChannel(): void
    {
        $registry = new ChannelRegistry();

        $registry->channel('private.user.{userId}', static function (ServerRequestInterface $request, string $userId): array|bool {
            if ((string) $request->getAttribute('user_id') !== $userId) {
                return false;
            }

            return ['user_id' => (int) $userId];
        });

        $service = new PushrHttpEndpoints($registry, 'app-1', 'secret-1');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn (string $name, mixed $default = null): mixed => $name === 'user_id' ? '10' : $default,
        );

        $result = $service->auth([
            'socket_id' => 'socket-1',
            'channel'   => 'private.user.10',
        ], $request);

        $payload = $result->payload();

        $this->assertSame(200, $result->status());
        $this->assertIsString($payload['auth']);
        $this->assertSame(['user_id' => 10], $payload['channelData']);
        $this->assertTrue(
            PushrChannelAuth::verify(
                'app-1',
                'secret-1',
                'socket-1',
                'private.user.10',
                (string) $payload['auth'],
                $payload['channelData'],
            ),
        );
    }

    #[Test]
    public function testNormalizePushrUrl(): void
    {
        $service = new PushrHttpEndpoints(new ChannelRegistry(), 'app-1', 'secret-1');

        $this->assertSame(
            'wss://example.test',
            $service->normalizePushrUrl('https://example.test', 'http'),
        );
        $this->assertSame(
            'ws://example.test',
            $service->normalizePushrUrl('http://example.test', 'https'),
        );
        $this->assertSame(
            'wss://example.test',
            $service->normalizePushrUrl('ws://example.test', 'https'),
        );
        $this->assertSame(
            'ws://example.test',
            $service->normalizePushrUrl('ws://example.test', 'http'),
        );
    }
}
