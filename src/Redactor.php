<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing;

use Illuminate\Support\Str;

/**
 * Masks context values whose key looks sensitive.
 *
 * Context reaches log files, queued job payloads and anything reading them
 * later, so a value that should not be written down must be caught on the way
 * out rather than trusted not to have been added.
 */
class Redactor
{
    /**
     * @param  list<string>  $patterns  Key patterns, `*` matching any run of characters.
     */
    public function __construct(
        private readonly array $patterns,
        private readonly string $replacement,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function apply(array $context): array
    {
        return $this->patterns === [] ? $context : $this->redact($context, '');
    }

    public function isSensitive(string $key): bool
    {
        return Str::is($this->patterns, Str::lower($key));
    }

    /**
     * Walk nested values too, matching a key by its own name or its full path.
     *
     * Without the descent, a secret is only ever as visible as its outermost
     * key: ['body' => ['password' => '…']] would be checked as "body", match
     * nothing, and be written out in full.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    protected function redact(array $context, string $prefix): array
    {
        foreach ($context as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if ($this->isSensitive((string) $key) || $this->isSensitive($path)) {
                $context[$key] = $this->replacement;

                continue;
            }

            if (is_array($value)) {
                $context[$key] = $this->redact($value, $path);
            }
        }

        return $context;
    }
}
