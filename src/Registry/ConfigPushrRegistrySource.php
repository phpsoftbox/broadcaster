<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Registry;

use PhpSoftBox\Broadcaster\Contracts\PushrRegistrySourceInterface;
use PhpSoftBox\Config\Config;

use function array_is_list;
use function is_array;
use function is_string;
use function trim;

final readonly class ConfigPushrRegistrySource implements PushrRegistrySourceInterface
{
    public function __construct(
        private Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    public function apps(array $options = []): array
    {
        $apps = [];

        $configuredApps = $this->config->get('pushr.apps', []);
        if (is_array($configuredApps)) {
            $apps = $this->normalizeConfiguredApps($configuredApps);
        }

        $shouldExcludeDefaultApp = (bool) ($options['without-default-app'] ?? false);
        if (!$shouldExcludeDefaultApp) {
            $defaultAppId  = $this->normalizeString($this->config->get('pushr.app_id'));
            $defaultSecret = $this->normalizeString($this->config->get('pushr.secret'));

            if ($defaultAppId !== null && $defaultSecret !== null) {
                $apps[$defaultAppId] = $defaultSecret;
            }
        }

        return $apps;
    }

    /**
     * @param array<mixed> $configuredApps
     * @return array<string, string>
     */
    private function normalizeConfiguredApps(array $configuredApps): array
    {
        $apps = [];

        if (array_is_list($configuredApps)) {
            foreach ($configuredApps as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $appId  = $this->normalizeString($row['app_id'] ?? $row['appId'] ?? null);
                $secret = $this->normalizeString($row['secret'] ?? null);
                if ($appId === null || $secret === null) {
                    continue;
                }

                $apps[$appId] = $secret;
            }

            return $apps;
        }

        foreach ($configuredApps as $appId => $secret) {
            $normalizedAppId  = $this->normalizeString($appId);
            $normalizedSecret = $this->normalizeString($secret);
            if ($normalizedAppId === null || $normalizedSecret === null) {
                continue;
            }

            $apps[$normalizedAppId] = $normalizedSecret;
        }

        return $apps;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
