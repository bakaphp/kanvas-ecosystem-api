<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Passes;

use App\GraphQL\Concerns\ResolvesActingContext;
use Illuminate\Support\Facades\Gate;
use Kanvas\Event\Passes\Actions\ScanEventPassAction;
use Kanvas\Event\Passes\DataTransferObject\PassScanOutcome;
use Kanvas\Inventory\Products\Models\Products;

class EventPassScanMutation
{
    use ResolvesActingContext;

    public function scan(mixed $root, array $request): PassScanOutcome
    {
        Gate::authorize('validate-pass');

        $ctx = $this->actingContext();
        $product = Products::getById((int) $request['product_id'], $ctx->app);

        return new ScanEventPassAction(
            $ctx->app,
            $ctx->user,
            $ctx->company,
            $product
        )->execute((string) $request['code']);
    }
}
