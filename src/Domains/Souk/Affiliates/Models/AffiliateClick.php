<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Models;

use Baka\Traits\NoCompanyRelationshipTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\Souk\Models\BaseModel;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $affiliates_id
 * @property int $affiliate_links_id
 * @property string|null $session_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $referrer_url
 * @property string|null $landing_page_url
 * @property string|null $device_type
 * @property string|null $browser
 * @property string|null $os
 * @property string|null $country_code
 * @property string $tracking_cookie
 * @property \Illuminate\Support\Carbon|null $cookie_expires_at
 * @property bool $converted
 * @property \Illuminate\Support\Carbon|null $conversion_date
 * @property \Illuminate\Support\Carbon $clicked_at
 */
class AffiliateClick extends BaseModel
{
    use UuidTrait;
    use NoCompanyRelationshipTrait;

    protected $table = 'affiliate_clicks';

    protected $guarded = [];

    public $timestamps = false;

    public const string CREATED_AT = 'clicked_at';

    #[Override]
    protected function casts(): array
    {
        return [
            'converted' => 'boolean',
            'conversion_date' => 'datetime',
            'clicked_at' => 'datetime',
            'cookie_expires_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class, 'affiliates_id');
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(AffiliateLink::class, 'affiliate_links_id');
    }

    public function conversion(): HasOne
    {
        return $this->hasOne(AffiliateConversion::class, 'affiliate_clicks_id');
    }

    /**
     * Check if the cookie is still valid.
     */
    public function isCookieValid(): bool
    {
        return $this->cookie_expires_at && $this->cookie_expires_at->isFuture();
    }

    /**
     * Check if this click resulted in a conversion.
     */
    public function hasConverted(): bool
    {
        return $this->converted;
    }

    /**
     * Mark as converted.
     */
    public function markAsConverted(): void
    {
        $this->converted = true;
        $this->conversion_date = now();
        $this->saveOrFail();
    }
}
