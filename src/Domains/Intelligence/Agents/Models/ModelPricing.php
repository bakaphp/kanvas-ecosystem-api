<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Models\BaseModel;
use Override;

/**
 * Versioned per-(provider, model) pricing. Inserts (never updates in place)
 * so historical snapshots stay correctly costed against the rate that was
 * in force when they were collected. Populated by SyncModelPricingCommand
 * pulling from LiteLLM/OpenRouter weekly.
 *
 * @property int $id
 * @property string $provider
 * @property string $model
 * @property string $input_per_million
 * @property string $output_per_million
 * @property string|null $cache_read_per_million
 * @property string|null $cache_write_per_million
 * @property Carbon $effective_from
 * @property Carbon|null $effective_until
 * @property string|null $notes
 * @property string $source
 * @property bool $is_deleted
 */
class ModelPricing extends BaseModel
{
    protected $table = 'model_pricing';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'input_per_million' => 'string',
            'output_per_million' => 'string',
            'cache_read_per_million' => 'string',
            'cache_write_per_million' => 'string',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'is_deleted' => 'boolean',
        ];
    }

    public function scopeActiveOn(Builder $query, Carbon $date): Builder
    {
        return $query
            ->where('effective_from', '<=', $date->toDateString())
            ->where(function (Builder $q) use ($date): void {
                $q->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $date->toDateString());
            });
    }

    public function scopeForModel(Builder $query, string $provider, string $model): Builder
    {
        return $query->where('provider', $provider)->where('model', $model);
    }
}
