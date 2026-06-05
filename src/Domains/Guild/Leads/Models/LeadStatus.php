<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
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
