<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\EnableCorporateModeAction;
use Kanvas\Connectors\Movipass\Enums\CorporateApplicationStatusEnum;

class EnableCorporateModeMutation
{
    public function enable(mixed $rootValue, array $request): array
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $company = new EnableCorporateModeAction(
            user: $user,
            app: $app,
            fields: $request['input'],
        )->execute();

        return [
            'company' => $company,
            'status' => CorporateApplicationStatusEnum::PENDING->value,
        ];
    }
}
