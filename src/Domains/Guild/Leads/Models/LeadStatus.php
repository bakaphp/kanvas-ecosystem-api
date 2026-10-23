<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Traits\PublicAppScopeTrait;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Guild\Models\BaseModel;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $name
 * @property int $is_default
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
class LeadStatus extends BaseModel
{
    use PublicAppScopeTrait;

    protected $table = 'leads_status';
    protected $guarded = [];

    /**
     * Statuses are free text per tenant ("Sold", "Closed Sold", "Closed - Not Sold"), so sold is
     * a name test, not an id. The one rule Lead::closeSold() has always applied.
     */
    public static function isSoldName(string $name): bool
    {
        $name = strtolower($name);

        return ! Str::contains($name, 'not') && Str::contains($name, 'sold');
    }

    /**
     * The sold status ids a company can put on a lead or deal: its own rows plus the platform
     * globals (apps_id 0 / companies_id 0) every tenant shares.
     *
     * @return array<int, int>
     */
    public static function soldIdsFor(AppInterface $app, CompanyInterface $company): array
    {
        return self::query()
            ->whereIn('apps_id', [0, $app->getId()])
            ->whereIn('companies_id', [0, $company->getId()])
            ->where('is_deleted', 0)
            ->get(['id', 'name'])
            ->filter(fn (self $status): bool => self::isSoldName($status->name))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Returns the default status for a given app+company, falling back to
     * the global default (apps_id=0, companies_id=0) when none is configured.
     */
    public static function getDefault(?AppInterface $app = null, ?CompanyInterface $company = null): self
    {
        if ($app !== null) {
            $key = $app->get('app-default-lead-status');

            if (! empty($key)) {
                try {
                    return self::where('name', $key)->firstOrFail();
                } catch (ModelNotFoundException) {
                }
            }
        }

        if ($company !== null && $app !== null) {
            $companyDefault = self::where('is_default', 1)
                ->where('apps_id', $app->getId())
                ->where('companies_id', $company->getId())
                ->first();

            if ($companyDefault !== null) {
                return $companyDefault;
            }
        }

        return self::where('is_default', 1)
            ->where('apps_id', 0)
            ->where('companies_id', 0)
            ->firstOrFail();
    }
}
