<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Jobs\AppendToLedgerJob;
use Throwable;

/**
 * Stateless dispatcher invoked by the EmitsNervousSystemEvents trait's
 * static event closures. Was previously an Eloquent Observer class, but
 * registering observers from a trait boot method caused re-entrant
 * Model boot (static::observe → new static → bootIfNotBooted). Static
 * event closures + this service avoid that.
 */
class LedgerEventDispatcher
{
    public static function recordCreated(Model $model): void
    {
        self::dispatchIfEnabled($model, 'created', payload: [
            'attributes' => self::scrub($model, $model->getAttributes()),
        ]);
    }

    public static function recordUpdated(Model $model): void
    {
        $changes = $model->getChanges();

        if (empty($changes)) {
            return;
        }

        $diff = [];
        foreach ($changes as $field => $newValue) {
            $diff[$field] = [$model->getOriginal($field), $newValue];
        }

        self::dispatchIfEnabled($model, 'updated', payload: [
            'diff' => self::scrubDiff($model, $diff),
        ]);
    }

    public static function recordDeleted(Model $model): void
    {
        self::dispatchIfEnabled($model, 'deleted', payload: [
            'attributes' => self::scrub($model, $model->getAttributes()),
        ]);
    }

    private static function dispatchIfEnabled(Model $model, string $eventType, array $payload): void
    {
        if (! method_exists($model, 'nervousSystemEventTypes')) {
            return;
        }

        if (! in_array($eventType, $model->nervousSystemEventTypes(), true)) {
            return;
        }

        if ((int) ($model->apps_id ?? 0) === 0) {
            return;
        }

        $app = $model->app ?? null;
        $company = $model->company ?? null;

        if ($app === null || $company === null) {
            return;
        }

        if (self::shouldSkipForSampling($model)) {
            return;
        }

        if (self::shouldSkipForDedupe($model, $eventType, $payload)) {
            return;
        }

        AppendToLedgerJob::dispatch(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: $model->nervousSystemSourceDomain(),
                eventType: $eventType,
                status: EventStatusEnum::INFO,
                sourceEntityType: $model::class,
                sourceEntityId: (int) $model->getKey(),
                actorType: self::actorType(),
                actorId: self::actorId(),
                payload: $payload,
                payloadSchemaVersion: 1,
                correlationId: self::correlationId(),
                causationId: self::causationId(),
                occurredAt: Carbon::now(),
            ),
        );
    }

    private static function scrub(Model $model, array $attributes): array
    {
        if (! method_exists($model, 'nervousSystemHiddenFields')) {
            return $attributes;
        }

        foreach ($model->nervousSystemHiddenFields() as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = '[redacted]';
            }
        }

        return $attributes;
    }

    private static function scrubDiff(Model $model, array $diff): array
    {
        if (! method_exists($model, 'nervousSystemHiddenFields')) {
            return $diff;
        }

        foreach ($model->nervousSystemHiddenFields() as $field) {
            if (array_key_exists($field, $diff)) {
                $diff[$field] = ['[redacted]', '[redacted]'];
            }
        }

        return $diff;
    }

    private static function actorType(): string
    {
        return Auth::user() !== null ? 'User' : 'System';
    }

    private static function actorId(): ?int
    {
        $user = Auth::user();

        return $user !== null ? (int) $user->getAuthIdentifier() : null;
    }

    private static function correlationId(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $header = request()->header('X-Correlation-ID');

        return is_string($header) ? $header : null;
    }

    private static function causationId(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $header = request()->header('X-Causation-ID');

        return is_string($header) ? $header : null;
    }

    private static function shouldSkipForSampling(Model $model): bool
    {
        if (! method_exists($model, 'nervousSystemSamplingRate')) {
            return false;
        }

        $rate = $model->nervousSystemSamplingRate();

        if ($rate >= 1.0) {
            return false;
        }

        if ($rate <= 0.0) {
            return true;
        }

        return mt_rand() / mt_getrandmax() > $rate;
    }

    /**
     * Redis-backed dedupe — when a model declares a dedupe window, identical
     * event fingerprints (model class + id + event_type + payload hash) within
     * the window are skipped. The first event wins; subsequent ones increment a
     * Redis counter and are dropped silently. When the window TTL expires, a
     * scheduled summary job (PR 1b cron) emits a single `*.deduped` event with
     * the suppressed count. Failures fall through to "do not skip" — losing
     * dedupe is preferable to losing the event.
     */
    private static function shouldSkipForDedupe(Model $model, string $eventType, array $payload): bool
    {
        if (! method_exists($model, 'nervousSystemDedupeWindowSeconds')) {
            return false;
        }

        $windowSeconds = $model->nervousSystemDedupeWindowSeconds();

        if ($windowSeconds <= 0) {
            return false;
        }

        $fingerprint = sprintf(
            'ns:dedupe:%s:%s:%s:%s',
            $model::class,
            (int) $model->getKey(),
            $eventType,
            md5(serialize($payload)),
        );

        try {
            $first = Redis::set($fingerprint, '1', 'EX', $windowSeconds, 'NX');

            if ($first) {
                return false;
            }

            Redis::incr($fingerprint . ':count');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
