<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\Setup;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Scribe\ElectronicInvoicing\Actions\ConfigureEcfRangeAction;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalNumberRange;

class EcfRangeMutation
{
    use ResolvesActingContext;

    public function configure(mixed $rootValue, array $request): FiscalNumberRange
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        $sequence = new ConfigureEcfRangeAction(
            app: $ctx->app,
            company: $ctx->company,
            type: (string) $input['document_type'],
            start: (string) $input['first_number'],
            end: (string) $input['last_number'],
            expiresAt: isset($input['expires_at']) ? (string) $input['expires_at'] : null,
        )->execute();

        return FiscalNumberRange::query()
            ->fromApp($ctx->app)
            ->fromCompany($ctx->company)
            ->where('id', $sequence->fiscal_number_range_id)
            ->firstOrFail();
    }
}
