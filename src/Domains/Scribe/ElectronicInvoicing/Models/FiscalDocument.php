<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfEnvironmentEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDirectionEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Exceptions\ImmutableFiscalRecordException;
use Kanvas\Scribe\ElectronicInvoicing\Models\Concerns\HasFiscalDocumentType;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * One row per issuer number (plan §2). The systemRef sent to providers is the attempt's `system_ref`
 * (FiscalDocumentSubmission), so neither a re-issue nor a resend after a reusable rejection is something the
 * provider has seen before. `current_key` is set only on the entity's current document
 * and is unique, so the database itself refuses two current rows.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property string $entity_type
 * @property int $entity_id
 * @property FiscalDirectionEnum $direction
 * @property string $country
 * @property string|null $provider
 * @property string $environment
 * @property string|null $schema_version
 * @property string $document_type
 * @property string|null $series
 * @property string $number
 * @property int|null $numbering_year
 * @property string|null $issuer_tax_id
 * @property string|null $receiver_tax_id
 * @property string $identity_key
 * @property string|null $authority_id
 * @property string|null $authority_seal
 * @property int|null $fiscal_number_range_id
 * @property FiscalDocumentStatusEnum $status
 * @property string|null $authority_status
 * @property bool|null $authority_number_consumed
 * @property Carbon|null $last_authority_update_at
 * @property bool $needs_review
 * @property string|null $current_key
 * @property Carbon|null $issued_at
 * @property Carbon|null $signed_at
 * @property Carbon|null $authorized_at
 * @property Carbon|null $frozen_at
 * @property Carbon|null $retain_until
 * @property string|null $currency
 * @property array<string, mixed>|null $regime_data
 * @property array<string, mixed>|null $authority_data
 * @property Model|null $entity
 * @property FiscalDocumentSubmission|null $latestSubmission
 */
class FiscalDocument extends FiscalBaseModel
{
    use HasFiscalDocumentType;
    use UuidTrait;

    /**
     * Identity, routing and the money snapshot: what the authority may already hold. Changing any of them after
     * the freeze would make our copy disagree with theirs.
     */
    public const string NUMBER_USED_ELSEWHERE = 'number_used_elsewhere';

    public const array FROZEN_COLUMNS = [
        'apps_id', 'companies_id', 'entity_type', 'entity_id', 'direction',
        'country', 'provider', 'environment', 'schema_version',
        'document_type', 'series', 'number', 'numbering_year', 'establishment', 'point_of_sale',
        'issuer_tax_id', 'receiver_tax_id', 'identity_key', 'fiscal_number_range_id', 'issued_at',
        'currency', 'exchange_rate', 'taxable_total', 'exempt_total', 'tax_total', 'total', 'regime_data',
    ];

    protected $table = 'fiscal_documents';

    protected function casts(): array
    {
        return [
            'direction' => FiscalDirectionEnum::class,
            'status' => FiscalDocumentStatusEnum::class,
            'authority_number_consumed' => 'boolean',
            'needs_review' => 'boolean',
            'last_authority_update_at' => 'datetime',
            'issued_at' => 'datetime',
            'signed_at' => 'datetime',
            'authorized_at' => 'datetime',
            'frozen_at' => 'datetime',
            'submit_deadline_at' => 'datetime',
            'retain_until' => 'date',
            'exchange_rate' => 'decimal:10',
            'taxable_total' => 'decimal:2',
            'exempt_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'regime_data' => Json::class,
            'authority_data' => Json::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $document) => $document->ensureNotFrozen());
    }

    public static function currentKeyFor(Model $entity, FiscalDirectionEnum $direction, string $environment): string
    {
        return implode('|', [$entity->getMorphClass(), $entity->getKey(), $direction->value, $environment]);
    }

    public static function currentFor(Model $entity, FiscalDirectionEnum $direction = FiscalDirectionEnum::ISSUED): ?self
    {
        /** @var self|null $document */
        $document = self::query()
            ->forEntity($entity)
            ->where('direction', $direction->value)
            ->whereNotNull('current_key')
            ->latest('id')
            ->first();

        return $document;
    }

    public function scopeForEntity(Builder $query, Model $entity): Builder
    {
        return $query
            ->where('entity_type', $entity->getMorphClass())
            ->where('entity_id', $entity->getKey());
    }

    public function ensureNotFrozen(): void
    {
        if ($this->getOriginal('frozen_at') === null) {
            return;
        }

        $changed = array_intersect(self::FROZEN_COLUMNS, array_keys($this->getDirty()));

        if ($changed !== []) {
            throw new ImmutableFiscalRecordException(
                "Fiscal document {$this->number} is frozen since its first submission; " . implode(', ', $changed) . ' cannot change.'
            );
        }
    }

    public function ecfEnvironment(): EcfEnvironmentEnum
    {
        return EcfEnvironmentEnum::from($this->environment);
    }

    public function isCurrent(): bool
    {
        return $this->current_key !== null;
    }

    /**
     * DGII's secuenciaUtilizada missing counts as consumed: reusing a number DGII may have counted is worse
     * than wasting one.
     */
    public function isBurned(): bool
    {
        return $this->status === FiscalDocumentStatusEnum::REJECTED && $this->authority_number_consumed !== false;
    }

    public function isReusable(): bool
    {
        return $this->status === FiscalDocumentStatusEnum::REJECTED && $this->authority_number_consumed === false;
    }

    public function entity(): MorphTo
    {
        return $this->morphTo('entity', 'entity_type', 'entity_id');
    }

    /**
     * The Scribe document behind the row; null when the morph points at something the fiscal code cannot act on.
     */
    public function scribeDocument(): Invoice|Bill|Expense|null
    {
        $entity = $this->entity;

        return $entity instanceof Invoice || $entity instanceof Bill || $entity instanceof Expense ? $entity : null;
    }

    public function numberRange(): BelongsTo
    {
        return $this->belongsTo(FiscalNumberRange::class, 'fiscal_number_range_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FiscalDocumentSubmission::class, 'fiscal_document_id');
    }

    public function latestSubmission(): HasOne
    {
        return $this->hasOne(FiscalDocumentSubmission::class, 'fiscal_document_id')->latestOfMany('attempt');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FiscalDocumentEvent::class, 'fiscal_document_id');
    }

    public function relations(): HasMany
    {
        return $this->hasMany(FiscalDocumentRelation::class, 'fiscal_document_id');
    }

    public function regimeValue(string $key): mixed
    {
        return ($this->regime_data ?? [])[$key] ?? null;
    }

    public function authorityValue(string $key): mixed
    {
        return ($this->authority_data ?? [])[$key] ?? null;
    }

    /**
     * The provider holds this number for another document, so polling can never settle the row. It also never
     * reached the authority under our name; a person voids the invoice and issues it again under a new number.
     */
    public function flagNumberHeldElsewhere(string $reason): void
    {
        $this->needs_review = true;
        $this->mergeAuthorityData(['action_required' => self::NUMBER_USED_ELSEWHERE, 'submission_error' => $reason]);
        $this->save();
    }

    public function isHeldElsewhere(): bool
    {
        return $this->authorityValue('action_required') === self::NUMBER_USED_ELSEWHERE && ! $this->status->isAccepted();
    }

    /**
     * A null value removes the key, so a cleared flag disappears instead of lingering as null.
     *
     * @param array<string, mixed> $changes
     */
    public function mergeAuthorityData(array $changes): void
    {
        $this->authority_data = array_filter(
            [...($this->authority_data ?? []), ...$changes],
            fn (mixed $value): bool => $value !== null
        );
    }
}
