<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Channel;

use Psr\Container\ContainerInterface;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use RuntimeException;

use function glob;
use function is_array;
use function is_callable;
use function is_dir;
use function is_file;
use function is_string;
use function sort;
use function str_contains;

final class ChannelLoader
{
    public function __construct(
        private readonly string $path,
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    public function load(ChannelRegistry $registry): void
    {
        foreach ($this->resolveFiles() as $file) {
            $definition = require $file;

            if (!is_callable($definition)) {
                throw new RuntimeException('Broadcaster channel file must return callable: ' . $file);
            }

            $this->callDefinition($definition, $registry);
        }
    }

    /**
     * @return list<string>
     */
    private function resolveFiles(): array
    {
        if (is_file($this->path)) {
            return [$this->path];
        }

        if (!is_dir($this->path)) {
            return [];
        }

        $files = glob($this->path . '/*.php');
        if ($files === false) {
            return [];
        }

        sort($files);

        return $files;
    }

    private function callDefinition(callable $definition, ChannelRegistry $registry): void
    {
        $paramCount = $this->callableParamCount($definition);

        if ($this->container !== null && $paramCount >= 2) {
            $definition($registry, $this->container);

            return;
        }

        $definition($registry);
    }

    /**
     * @throws ReflectionException
     */
    private function callableParamCount(callable $definition): int
    {
        if (is_array($definition)) {
            $reflection = new ReflectionMethod($definition[0], $definition[1]);

            return $reflection->getNumberOfParameters();
        }

        if (is_string($definition) && str_contains($definition, '::')) {
            $reflection = new ReflectionMethod($definition);

            return $reflection->getNumberOfParameters();
        }

        $reflection = new ReflectionFunction($definition(...));

        return $reflection->getNumberOfParameters();
    }
}
