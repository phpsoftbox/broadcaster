<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Http;

use PhpSoftBox\Broadcaster\Channel\ChannelRegistry;
use PhpSoftBox\Broadcaster\Pushr\PushrChannelAuth;
use PhpSoftBox\Broadcaster\Pushr\PushrSignature;
use Psr\Http\Message\ServerRequestInterface;

use function preg_replace;
use function str_starts_with;
use function strtolower;
use function time;

final readonly class PushrHttpEndpoints
{
    public function __construct(
        private ChannelRegistry $channels,
        private string $appId,
        private string $secret,
        private string $publicUrl = '',
    ) {
    }

    public function connect(string $scheme): BroadcastResponse
    {
        if (!$this->configured()) {
            return new BroadcastResponse(['error' => 'Pushr is not configured.'], 500);
        }

        $timestamp = time();
        $signature = PushrSignature::generate($this->appId, $this->secret, $timestamp);
        $url       = $this->normalizePushrUrl($this->publicUrl, $scheme);

        $payload = [
            'appId'     => $this->appId,
            'timestamp' => $timestamp,
            'signature' => $signature,
        ];

        if ($url !== '') {
            $payload['url'] = $url;
        }

        return new BroadcastResponse($payload);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function auth(array $body, ServerRequestInterface $request): BroadcastResponse
    {
        $socketId = (string) ($body['socket_id'] ?? '');
        $channel  = (string) ($body['channel'] ?? '');

        if ($socketId === '' || $channel === '') {
            return new BroadcastResponse(['error' => 'socket_id and channel are required.'], 422);
        }

        if (!$this->configured()) {
            return new BroadcastResponse(['error' => 'Pushr is not configured.'], 500);
        }

        $authorization = $this->channels->authorize($channel, $request);
        if (!$authorization->authorized()) {
            return new BroadcastResponse(['error' => 'Unauthorized.'], 403);
        }

        $channelData = $authorization->channelData();
        $auth        = PushrChannelAuth::token($this->appId, $this->secret, $socketId, $channel, $channelData);
        $response    = ['auth' => $auth];

        if ($channelData !== null) {
            $response['channelData'] = $channelData;
        }

        return new BroadcastResponse($response);
    }

    public function normalizePushrUrl(string $url, string $scheme): string
    {
        if ($url === '') {
            return $url;
        }

        if (str_starts_with($url, 'https:')) {
            return preg_replace('/^https:/i', 'wss:', $url) ?? $url;
        }

        if (str_starts_with($url, 'http:')) {
            return preg_replace('/^http:/i', 'ws:', $url) ?? $url;
        }

        if (strtolower($scheme) === 'https' && str_starts_with($url, 'ws:')) {
            return preg_replace('/^ws:/i', 'wss:', $url) ?? $url;
        }

        return $url;
    }

    private function configured(): bool
    {
        return $this->appId !== '' && $this->secret !== '';
    }
}
