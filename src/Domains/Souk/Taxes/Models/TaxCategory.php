<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Models;

use Baka\Contracts\AppInterface;
use Baka\Traits\NoCompanyRelationshipTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Taxes\Enums\TaxCategoryEnum;

class TaxCategory extends BaseModel
{
    use NoCompanyRelationshipTrait;
    use UuidTrait;

    public const int CODE_MAX_LENGTH = 32;

    protected $table = 'tax_categories';
    protected $guarded = [];

    public static function normalizeCode(string $code): string
    {
        return Str::slug($code, '_');
    }

    public static function findByCode(AppInterface $app, string $code): ?self
    {
        return self::query()
            ->fromApp($app)
            ->notDeleted()
            ->where('code', $code)
            ->first();
    }

    public static function assertCodesExist(AppInterface $app, array|string|null $codes): void
    {
        $codes = collect((array) $codes)
            ->filter(fn ($code) => $code !== null && $code !== '')
            ->reject(fn ($code) => $code === TaxCategoryEnum::NON_TAXABLE->value)
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            return;
        }

        $known = self::query()
            ->fromApp($app)
            ->notDeleted()
            ->whereIn('code', $codes->all())
            ->pluck('code');

        $unknown = $codes->diff($known);

        if ($unknown->isNotEmpty()) {
            throw new ValidationException('Unknown tax category: ' . $unknown->implode(', '));
        }
    }
}
