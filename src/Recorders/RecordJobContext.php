<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing\Recorders;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Arr;
use Niladam\LaravelTracing\Channel;
use Niladam\LaravelTracing\Contracts\Recorder;
use Niladam\LaravelTracing\Redactor;
use Niladam\LaravelTracing\SensitiveParameters;
use Throwable;

/**
 * Records which job a line was logged from.
 *
 * A trace alone tells you a job ran; this tells you which one, on what queue,
 * and on which attempt — the questions asked immediately afterwards.
 *
 * These keys never leave the worker: the provider strips the prefix on the way
 * into a job payload, so a job's children carry their own details, not its.
 */
class RecordJobContext implements Recorder
{
    public static function listensTo(): string
    {
        return JobProcessing::class;
    }

    public function __construct(
        private readonly SensitiveParameters $sensitive,
        private readonly Redactor $redactor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(object $event): array
    {
        $prefix = (string) config('tracing.context.jobs.prefix', 'job');
        $payload = $event->job->payload();
        $name = $this->jobName($event, $payload);

        return [
            'channel' => Channel::Queue,
            "{$prefix}.name" => $name,
            "{$prefix}.connection" => $event->connectionName,
            "{$prefix}.queue" => $event->job->getQueue(),
            "{$prefix}.attempts" => $event->job->attempts(),
            "{$prefix}.uuid" => $event->job->uuid(),
            ...$this->arguments($name, $payload, $prefix),
        ];
    }

    /**
     * The command a payload wraps, falling back to the display name.
     *
     * The display name is not always a class — a queued closure reports
     * `Closure (file.php:12)` — so the wrapped command is the better answer
     * whenever the payload carries one.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function jobName(JobProcessing $event, array $payload): string
    {
        return $payload['data']['commandName'] ?? $event->job->resolveName();
    }

    /**
     * The job's own properties, minus anything it declared sensitive.
     *
     * Off by default: a payload can be large, and a job is free to hold things
     * that have no business being written to a log file.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function arguments(string $name, array $payload, string $prefix): array
    {
        if (! config('tracing.context.jobs.arguments', false) || ! isset($payload['data']['command'])) {
            return [];
        }

        try {
            $properties = json_decode(json_encode(unserialize($payload['data']['command'])) ?: '{}', true);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($properties)) {
            return [];
        }

        $properties = Arr::except($properties, $this->sensitive->for($name));

        return Arr::prependKeysWith(
            $this->redactor->apply(Arr::dot($properties)),
            "{$prefix}.arguments.",
        );
    }
}
