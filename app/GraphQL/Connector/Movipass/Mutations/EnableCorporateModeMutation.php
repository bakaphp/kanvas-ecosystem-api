<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;
use Kanvas\Connectors\Movipass\Actions\EnableCorporateModeAction;

class EnableCorporateModeMutation
{
    use ResolvesActingContext;

    public function enable(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();

        $company = new EnableCorporateModeAction(
            user: $ctx->user,
            app: $ctx->app,
            fields: $request['input'],
        )->execute();

        return [
            'company' => $company,
            'status' => CorporateApplicationStatusEnum::PENDING->value,
        ];
    }
}
