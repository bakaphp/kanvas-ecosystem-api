<?php

declare(strict_types=1);

namespace Kanvas\Scribe\DocumentSequences\Models;

use Baka\Traits\KanvasAppScopesTrait;
use Baka\Traits\KanvasCompanyScopesTrait;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;

/**
 * Atomic document-number allocator state.
 *
 * NOT extending Scribe\Models\BaseModel — this table has no apps_id+companies_id partition (it HAS them, but it's
 * not a user-facing entity with soft delete / custom fields / files). The DocumentNumberAllocatorService service is the
 * sole writer; never write to this table directly via Eloquent in business code.
 *
 * A row with a range is an authority-issued block (a DGII e-NCF range): numbers come only from inside it
 * and only until it expires.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property DocumentTypeEnum $document_type
 * @property string $prefix
 * @property int $next_value
 * @property int|null $range_start
 * @property int|null $range_end
 * @property Carbon|null $expires_at
 * @property int|null $padding
 * @property int|null $fiscal_number_range_id
 */
class DocumentSequence extends EloquentModel
{
    use KanvasAppScopesTrait;
    use KanvasCompanyScopesTrait;

    public const float USAGE_ALERT_RATIO = 0.8;
    public const int EXPIRY_ALERT_DAYS = 60;

    protected $connection = 'accounting';
    protected $table = 'document_sequences';
    protected $guarded = [];

    protected $casts = [
        'document_type' => DocumentTypeEnum::class,
        'next_value' => 'integer',
        'range_start' => 'integer',
        'range_end' => 'integer',
        'expires_at' => 'date',
        'padding' => 'integer',
        'fiscal_number_range_id' => 'integer',
    ];

    public function hasRange(): bool
    {
        return $this->range_end !== null;
    }

    public function format(int $value): string
    {
        $number = $this->padding !== null && $this->padding > 0
            ? str_pad((string) $value, $this->padding, '0', STR_PAD_LEFT)
            : (string) $value;

        return $this->prefix . $number;
    }

    public function isExpired(?Carbon $today = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(($today ?? Carbon::today())->startOfDay());
    }

    public function isExhausted(): bool
    {
        return $this->range_end !== null && $this->next_value > $this->range_end;
    }

    /**
     * An authorized range with a number left to give today.
     */
    public function isUsableRange(?Carbon $today = null): bool
    {
        return $this->hasRange() && ! $this->isExpired($today) && ! $this->isExhausted();
    }

    public function remaining(): ?int
    {
        return $this->range_end !== null ? max(0, $this->range_end - $this->next_value + 1) : null;
    }

    public function usedRatio(): ?float
    {
        if ($this->range_start === null || $this->range_end === null) {
            return null;
        }

        $size = $this->range_end - $this->range_start + 1;

        return $size > 0 ? ($this->next_value - $this->range_start) / $size : 1.0;
    }

    /**
     * Why someone should request a new range now — empty when nothing needs attention.
     *
     * @return list<string>
     */
    public function attentionReasons(?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $reasons = [];

        if (($this->usedRatio() ?? 0.0) >= self::USAGE_ALERT_RATIO) {
            $reasons[] = 'usage';
        }

        if ($this->expires_at !== null && $today->diffInDays($this->expires_at, false) <= self::EXPIRY_ALERT_DAYS) {
            $reasons[] = 'expiry';
        }

        return $reasons;
    }
}
