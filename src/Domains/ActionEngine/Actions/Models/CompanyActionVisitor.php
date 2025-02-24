<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Actions\Models;

use Baka\Casts\Json;
use Baka\Traits\NoAppRelationshipTrait;
use Kanvas\ActionEngine\Models\BaseModel;

/**
 * Class CompanyAction.
 *
 * @property int $id
 * @property string $visors_id
 * @property string $leads_id
 * @property string $receivers_id
 * @property string $contacts_id
 * @property string $companies_id
 * @property string $users_id
 * @property string $companies_actions_id
 * @property string $actions_slug
 * @property string $request
 */
class CompanyActionVisitor extends BaseModel
{
    use NoAppRelationshipTrait;

    protected $table = 'companies_actions_visitors';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'request' => Json::class,
        ];
    }
}
