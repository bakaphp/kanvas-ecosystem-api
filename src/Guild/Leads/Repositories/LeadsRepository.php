<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Repositories;

use Baka\Enums\StateEnums;
use Baka\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;

class LeadsRepository
{
    use SearchableTrait;

    public static function getModel(): Model
    {
        return new Lead();
    }

     /**
     * @psalm-suppress MixedReturnStatement
     */
    public static function getDefaultReceiver(CompaniesBranches $branch): LeadReceiver
    {
        return LeadReceiver::where('companies_branches_id', $branch->getId())
                    ->where('is_default', StateEnums::YES->getValue())
                    ->firstOrFail();
    }
}
