<?php

namespace Tests\Support;

use LogicException;

/** Evaluates the generated Rules language subset, with Cloudflare's slash-spanning wildcards. */
class CloudflareExpression
{
    private array $tokens;

    private int $position = 0;

    public function __construct(string $expression, private string $host, private string $path)
    {
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|[(){}]|[^\s(){}]+/', $expression, $matches);
        $this->tokens = $matches[0];
    }

    public function matches(): bool
    {
        $result = $this->disjunction();

        if ($this->position !== count($this->tokens)) {
            throw new LogicException('Unexpected tokens: '.implode(' ', array_slice($this->tokens, $this->position)));
        }

        return $result;
    }

    private function disjunction(): bool
    {
        $result = $this->conjunction();

        while ($this->take('or')) {
            $right = $this->conjunction();
            $result = $result || $right;
        }

        return $result;
    }

    private function conjunction(): bool
    {
        $result = $this->comparison();

        while ($this->take('and')) {
            $right = $this->comparison();
            $result = $result && $right;
        }

        return $result;
    }

    private function comparison(): bool
    {
        if ($this->take('not')) {
            return ! $this->comparison();
        }

        if ($this->take('(')) {
            $result = $this->disjunction();
            $this->expect(')');

            return $result;
        }

        if ($this->take('true')) {
            return true;
        }

        $field = $this->tokens[$this->position++] ?? '';
        $value = match ($field) {
            'http.host' => $this->host,
            'http.request.uri.path' => $this->path,
            default => throw new LogicException("Unknown field: {$field}"),
        };
        $operator = $this->tokens[$this->position++];

        if ($operator === 'in') {
            $this->expect('{');
            $values = [];

            while (! $this->take('}')) {
                $values[] = json_decode($this->tokens[$this->position++], true, flags: JSON_THROW_ON_ERROR);
            }

            return in_array($value, $values, true);
        }

        $pattern = json_decode($this->tokens[$this->position++], true, flags: JSON_THROW_ON_ERROR);

        return match ($operator) {
            'eq' => $value === $pattern,
            'ne' => $value !== $pattern,
            'contains' => str_contains($value, $pattern),
            'wildcard' => (bool) preg_match('~^'.str_replace('\\*', '.*', preg_quote($pattern, '~')).'$~is', $value),
            default => throw new LogicException("Unknown operator: {$operator}"),
        };
    }

    private function take(string $token): bool
    {
        if (($this->tokens[$this->position] ?? null) !== $token) {
            return false;
        }

        $this->position++;

        return true;
    }

    private function expect(string $token): void
    {
        if (! $this->take($token)) {
            throw new LogicException("Expected {$token}");
        }
    }
}
