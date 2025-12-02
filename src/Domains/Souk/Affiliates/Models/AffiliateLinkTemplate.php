<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Models\BaseModel;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $affiliates_id
 * @property string $name
 * @property string|null $description
 * @property string|null $category
 * @property string $link_type
 * @property array|null $config_template
 * @property int $usage_count
 * @property \Illuminate\Support\Carbon|null $last_used_at
 */
class AffiliateLinkTemplate extends BaseModel
{
    use UuidTrait;

    protected $table = 'affiliate_link_templates';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'config_template' => Json::class,
            'usage_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class, 'affiliates_id');
    }

    /**
     * Increment usage count.
     */
    public function incrementUsage(): void
    {
        $this->increment('usage_count');
        $this->last_used_at = now();
        $this->saveOrFail();
    }

    /**
     * Create a link from this template.
     */
    public function createLink(array $overrides = []): AffiliateLink
    {
        $affiliate = $this->affiliate;
        
        $linkData = array_merge([
            'apps_id' => $this->apps_id,
            'companies_id' => $affiliate->companies_id,
            'affiliates_id' => $this->affiliates_id,
            'link_type' => $this->link_type,
            'config' => $this->config_template,
        ], $overrides);

        $link = AffiliateLink::create($linkData);
        $this->incrementUsage();

        return $link;
    }
}
