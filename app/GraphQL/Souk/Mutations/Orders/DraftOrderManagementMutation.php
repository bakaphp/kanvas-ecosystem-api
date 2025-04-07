<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Souk\Orders\Actions\CreateDraftOrderAction;
use Kanvas\Souk\Orders\DataTransferObject\DraftOrder;
use Kanvas\Souk\Orders\Models\Order;

class DraftOrderManagementMutation
{
    public function create(mixed $root, array $request): Order
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $branch = app(CompaniesBranches::class);

        if ($branch->id === null) {
            throw new ValidationException('Missing Location Header');
        }

        $region = Regions::getByIdFromCompanyApp($request['input']['region_id'], $branch->company, $app);

        $draftOrder = DraftOrder::viaRequest(
            $app,
            $branch,
            $user,
            $region,
            $request
        );

        return (new CreateDraftOrderAction($draftOrder))->execute();
    }
}
