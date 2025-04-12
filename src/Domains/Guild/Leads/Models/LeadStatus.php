<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Contracts\AppInterface;
use Baka\Traits\NoAppRelationshipTrait;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class LeadStatus.
 *
 * @property int $id
 * @property string $name
 * @property int $is_default
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 *
 * @todo add company_id
 */
class LeadStatus extends BaseModel
{
    use NoAppRelationshipTrait;

    protected $table = 'leads_status';
    protected $guarded = [];

    public static function getDefault(?AppInterface $app = null): self
    {
        if ($app !== null) {
            $key = $app->get('app-default-lead-status');

            if (! empty($key)) {
                try {
                    return self::where('name', $key)->firstOrFail();
                } catch (ModelNotFoundException $e) {
                    // Handle the exception if needed
                }
            }
        }

        return self::where('is_default', 1)->firstOrFail();
    }
}
