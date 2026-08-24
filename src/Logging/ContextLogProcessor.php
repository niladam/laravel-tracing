<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing\Logging;

use Illuminate\Container\Container;
use Illuminate\Contracts\Log\ContextLogProcessor as ContextLogProcessorContract;
use Illuminate\Log\Context\ContextLogProcessor as FrameworkContextLogProcessor;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Arr;
use Monolog\LogRecord;
use Niladam\LaravelTracing\Redactor;

/**
 * Folds the ambient context into the record's "context" instead of its "extra".
 *
 * {@see FrameworkContextLogProcessor} writes to "extra", which Monolog's
 * LineFormatter renders as a second JSON blob after `%context%`. Merging both
 * into one keeps every line to a single object, which is what most log viewers
 * expect. Per-call keys win over ambient ones, being the more specific of the two.
 */
class ContextLogProcessor implements ContextLogProcessorContract
{
    public function __construct(
        private readonly Redactor $redactor,
        private readonly bool $flattenContext = false,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $app = Container::getInstance();

        if (! $app->bound(ContextRepository::class)) {
            return $record;
        }

        $context = [
            ...$app->get(ContextRepository::class)->all(),
            ...$record->context,
        ];

        $context = $this->redactor->apply($this->flatten($context), $redacted);

        return $record->with(context: $this->named($context, $redacted));
    }

    /**
     * Name what was masked, so the keys a pattern is missing are visible.
     *
     * Scanning a blob for "[redacted]" tells you what was caught; a list tells
     * you the same at a glance, and makes the gaps obvious next to it.
     *
     * @param  array<string, mixed>  $context
     * @param  list<string>  $redacted
     * @return array<string, mixed>
     */
    protected function named(array $context, array $redacted): array
    {
        if ($redacted === [] || ! config('tracing.logs.redact.name_redacted_keys', true)) {
            return $context;
        }

        return [...$context, 'redacted_keys' => $redacted];
    }

    /**
     * Flatten before redacting, never after.
     *
     * A nested ['body' => ['password' => '…']] is only reachable by a "*password*"
     * pattern once it has become the key "body.password"; redacting first would
     * check "body" and wave the secret through.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function flatten(array $context): array
    {
        return $this->flattenContext ? Arr::dot($context) : $context;
    }
}
