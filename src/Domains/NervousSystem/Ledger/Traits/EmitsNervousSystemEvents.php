<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Traits;

use Illuminate\Database\Eloquent\Model;
use Kanvas\NervousSystem\Ledger\Services\LedgerEventDispatcher;

/**
 * Opt-in trait that wires Eloquent lifecycle (created/updated/deleted)
 * into the Nervous System ledger. Models that use this trait emit one
 * AppendToLedgerJob per applicable event.
 *
 * Customization (override on the model):
 *   protected array $nervousSystemEventTypes = ['created', 'updated', 'deleted'];
 *   protected array $nervousSystemHiddenFields = ['credit_card_token'];
 *   protected string $nervousSystemSourceDomain = 'Guild';
 *
 * Implementation note: we register static event closures in the
 * trait boot method rather than calling static::observe(). The latter
 * triggers `new static` during Model::boot(), which Laravel rejects with
 * "may not be called on model [...] while it is being booted". Closure
 * registration avoids the re-entrant boot.
 */
trait EmitsNervousSystemEvents
{
    public static function bootEmitsNervousSystemEvents(): void
    {
        static::created(function (Model $model): void {
            LedgerEventDispatcher::recordCreated($model);
        });

        static::updated(function (Model $model): void {
            LedgerEventDispatcher::recordUpdated($model);
        });

        static::deleted(function (Model $model): void {
            LedgerEventDispatcher::recordDeleted($model);
        });
    }

    /**
     * Lifecycle events this model emits to the ledger.
     * Defaults to all three; override by declaring the property on the model.
     *
     * Note: we use property_exists rather than $this->prop because Eloquent's
     * __get treats unknown property reads as relation lookups, which throws
     * when a method with the same name returns a non-relation.
     *
     * @return array<int, string>
     */
    public function nervousSystemEventTypes(): array
    {
        if (property_exists($this, 'nervousSystemEventTypes')) {
            return $this->nervousSystemEventTypes;
        }

        return ['created', 'updated', 'deleted'];
    }

    /**
     * Fields to strip from the diff before it lands in the ledger payload.
     *
     * @return array<int, string>
     */
    public function nervousSystemHiddenFields(): array
    {
        if (property_exists($this, 'nervousSystemHiddenFields')) {
            return $this->nervousSystemHiddenFields;
        }

        return [];
    }

    /**
     * The source_domain column value for events emitted by this model.
     * Defaults to the second segment of the namespace (e.g.
     * "Kanvas\Guild\Leads\Models\Lead" → "Guild").
     */
    public function nervousSystemSourceDomain(): string
    {
        if (property_exists($this, 'nervousSystemSourceDomain')) {
            return $this->nervousSystemSourceDomain;
        }

        $parts = explode('\\', static::class);

        return $parts[1] ?? 'Unknown';
    }
}
