<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\DocumentSequences\Models\DocumentSequence;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalRangeStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Models\Concerns\HasFiscalDocumentType;

/**
 * An authority-authorized block of numbers. Configuring a new one supersedes the previous instead of
 * overwriting it, so the numbers of every range ever authorized stay accountable.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $environment
 * @property string $country
 * @property string $document_type
 * @property string|null $series
 * @property int $range_from
 * @property int $range_to
 * @property int|null $padding
 * @property Carbon|null $expires_at
 * @property FiscalRangeStatusEnum $status
 * @property DocumentSequence|null $sequence
 */
class FiscalNumberRange extends FiscalBaseModel
{
    use HasFiscalDocumentType;

    protected $table = 'fiscal_number_ranges';

    protected function casts(): array
    {
        return [
            'status' => FiscalRangeStatusEnum::class,
            'range_from' => 'integer',
            'range_to' => 'integer',
            'padding' => 'integer',
            'authorized_at' => 'datetime',
            'expires_at' => 'date',
        ];
    }

    public function format(int $value): string
    {
        $number = $this->padding !== null && $this->padding > 0
            ? str_pad((string) $value, $this->padding, '0', STR_PAD_LEFT)
            : (string) $value;

        return ($this->series ?? '') . $number;
    }

    /**
     * The allocation cursor; it points at the active range only, so a superseded range has none.
     */
    public function sequence(): HasOne
    {
        return $this->hasOne(DocumentSequence::class, 'fiscal_number_range_id');
    }

    public function firstNumber(): string
    {
        return $this->format($this->range_from);
    }

    public function lastNumber(): string
    {
        return $this->format($this->range_to);
    }

    public function nextNumber(): ?string
    {
        $sequence = $this->status === FiscalRangeStatusEnum::ACTIVE ? $this->sequence : null;

        return $sequence === null ? null : $this->format($sequence->next_value);
    }

    public function remaining(): ?int
    {
        return $this->status === FiscalRangeStatusEnum::ACTIVE ? $this->sequence?->remaining() : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'id' => $this->getId(),
            'document_type' => $this->document_type,
            'document_type_label' => $this->documentTypeLabel(),
            'first_number' => $this->firstNumber(),
            'last_number' => $this->lastNumber(),
            'next_number' => $this->nextNumber(),
            'remaining' => $this->remaining(),
            'expires_at' => $this->expires_at?->toDateString(),
            'status' => $this->status->value,
            'environment' => $this->environment,
        ];
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }
}
