<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing\Contracts;

/**
 * Records context at the moment its facts become true.
 *
 * A recorder names the event it waits for and returns the keys to merge, so
 * nothing depends on middleware order or on when anything else runs. List it
 * in `tracing.record` to switch it on; leave it out to switch it off.
 *
 * ```php
 * final class RecordTenantContext implements Recorder
 * {
 *     public static function listensTo(): string
 *     {
 *         return TenantResolved::class;
 *     }
 *
 *     public function __invoke(TenantResolved $event): array
 *     {
 *         return ['tenant_id' => $event->tenant->id];
 *     }
 * }
 * ```
 */
interface Recorder
{
    /**
     * The event that makes this recorder's facts true.
     *
     * @return class-string
     */
    public static function listensTo(): string;

    /**
     * @return array<string, mixed>
     */
    public function __invoke(object $event): array;
}
