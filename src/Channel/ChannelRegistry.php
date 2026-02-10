<?php

declare(strict_types=1);

namespace PhpSoftBox\Broadcaster\Channel;

use Closure;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionFunction;
use ReflectionMethod;
use RuntimeException;

use function array_values;
use function count;
use function is_array;
use function is_string;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function str_contains;
use function strlen;
use function substr;
use function trim;

use const PREG_OFFSET_CAPTURE;

final class ChannelRegistry
{
    /** @var list<array{pattern:string,regex:string,params:list<string>,authorizer:callable}> */
    private array $rules = [];

    public function channel(string $pattern, callable $authorizer): self
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            throw new RuntimeException('Channel pattern must be non-empty.');
        }

        $this->rules[] = [
            'pattern'    => $pattern,
            'regex'      => $this->compilePattern($pattern, $params),
            'params'     => $params,
            'authorizer' => $authorizer,
        ];

        return $this;
    }

    public function authorize(string $channel, ServerRequestInterface $request): ChannelAuthorization
    {
        foreach ($this->rules as $rule) {
            if (!$this->match($rule['regex'], $rule['params'], $channel, $values)) {
                continue;
            }

            $args       = array_values($values);
            $authorizer = $rule['authorizer'];
            $result     = $this->callAuthorizer($authorizer, $request, $args);

            if ($result === false || $result === null) {
                return new ChannelAuthorization(false, null, $rule['pattern'], $values);
            }

            if ($result === true) {
                return new ChannelAuthorization(true, null, $rule['pattern'], $values);
            }

            return new ChannelAuthorization(true, $result, $rule['pattern'], $values);
        }

        return new ChannelAuthorization(false);
    }

    /**
     * @return array<int, array{pattern:string,regex:string,params:list<string>,authorizer:callable}>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * @param list<string> $params
     */
    private function compilePattern(string $pattern, ?array &$params): string
    {
        $params = [];
        $regex  = '';
        $offset = 0;

        if (!str_contains($pattern, '{')) {
            return '/^' . preg_quote($pattern, '/') . '$/';
        }

        $matches = [];
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $pattern, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => $match) {
            $start   = $match[1];
            $length  = strlen($match[0]);
            $literal = substr($pattern, $offset, $start - $offset);
            $regex .= preg_quote($literal, '/');

            $name     = $matches[1][$index][0];
            $params[] = $name;
            $regex .= '(?P<' . $name . '>[^.]+)';

            $offset = $start + $length;
        }

        if ($offset < strlen($pattern)) {
            $regex .= preg_quote(substr($pattern, $offset), '/');
        }

        return '/^' . $regex . '$/';
    }

    /**
     * @param list<string> $params
     * @param array<string, string> $values
     */
    private function match(string $regex, array $params, string $channel, ?array &$values): bool
    {
        if (preg_match($regex, $channel, $matches) !== 1) {
            return false;
        }

        $values = [];
        foreach ($params as $param) {
            if (isset($matches[$param])) {
                $values[$param] = (string) $matches[$param];
            }
        }

        return true;
    }

    /**
     * @param list<string> $params
     */
    private function callAuthorizer(callable $authorizer, ServerRequestInterface $request, array $params): mixed
    {
        $paramCount = $this->callableParamCount($authorizer);

        if ($paramCount === count($params)) {
            return $authorizer(...$params);
        }

        return $authorizer($request, ...$params);
    }

    private function callableParamCount(callable $authorizer): int
    {
        if (is_array($authorizer)) {
            $reflection = new ReflectionMethod($authorizer[0], $authorizer[1]);

            return $reflection->getNumberOfParameters();
        }

        if (is_string($authorizer) && str_contains($authorizer, '::')) {
            $reflection = new ReflectionMethod($authorizer);

            return $reflection->getNumberOfParameters();
        }

        $reflection = new ReflectionFunction(Closure::fromCallable($authorizer));

        return $reflection->getNumberOfParameters();
    }
}
