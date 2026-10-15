<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Guild\Leads\Actions\SyncCrmLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\CrmLeadSync;

class CrmLeadMutation
{
    use ResolvesActingContext;

    public function sync(mixed $root, array $req): array
    {
        $ctx = $this->actingContext();

        $result = new SyncCrmLeadAction(
            CrmLeadSync::from($ctx->app, $ctx->user, $ctx->company, $req['input'])
        )->execute();

        return [
            'outcome' => $result->outcome->value,
            'people' => $result->people,
            'lead' => $result->lead,
            'people_created' => $result->peopleCreated,
            'lead_created' => $result->leadCreated,
            'replayed' => $result->replayed,
            'warnings' => $result->warnings,
        ];
    }
}
