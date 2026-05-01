<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Jobs\AppendToLedgerJob;

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
                correlationId: self::correlationId(),
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
}
