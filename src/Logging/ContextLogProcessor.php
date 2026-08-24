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

        return $record->with(context: $this->redactor->apply($this->flatten($context)));
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
