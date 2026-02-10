<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Registry;

use PhpSoftBox\Broadcaster\Contracts\PushrRegistryBuilderInterface;
use PhpSoftBox\Broadcaster\Contracts\PushrRegistrySourceInterface;
use PhpSoftBox\Broadcaster\Pushr\PushrAppRegistry;
use RuntimeException;

use function is_int;
use function is_string;
use function trim;

final readonly class CompositePushrRegistryBuilder implements PushrRegistryBuilderInterface
{
    /**
     * @param iterable<PushrRegistrySourceInterface> $sources
     */
    public function __construct(
        private iterable $sources,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function build(array $options = []): PushrAppRegistry
    {
        $apps      = [];
        $hasSource = false;

        foreach ($this->sources as $source) {
            $hasSource = true;
            foreach ($source->apps($options) as $appId => $secret) {
                if (is_int($appId)) {
                    $appId = (string) $appId;
                }

                if (!is_string($appId) || trim($appId) === '') {
                    throw new RuntimeException('Некорректный Pushr app_id в источнике реестра.');
                }

                if (!is_string($secret) || trim($secret) === '') {
                    throw new RuntimeException('Некорректный Pushr secret для app_id "' . $appId . '".');
                }

                $appId  = trim($appId);
                $secret = trim($secret);

                if (isset($apps[$appId]) && $apps[$appId] !== $secret) {
                    throw new RuntimeException('Конфликт Pushr app_id "' . $appId . '" в источниках реестра.');
                }

                $apps[$appId] = $secret;
            }
        }

        if (!$hasSource) {
            throw new RuntimeException('Не настроены источники Pushr реестра.');
        }

        if ($apps === []) {
            throw new RuntimeException('Не найдено ни одного приложения для Pushr.');
        }

        return new PushrAppRegistry($apps);
    }
}
