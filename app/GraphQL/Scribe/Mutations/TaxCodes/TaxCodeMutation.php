<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\TaxCodes;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\TaxCodes\Actions\CreateTaxCodeAction;
use Kanvas\Scribe\TaxCodes\Actions\UpdateTaxCodeAction;
use Kanvas\Scribe\TaxCodes\DataTransferObject\TaxCode as TaxCodeData;
use Kanvas\Scribe\TaxCodes\DataTransferObject\TaxRate as TaxRateData;
use Kanvas\Scribe\TaxCodes\Models\TaxCode;
use Spatie\LaravelData\DataCollection;

class TaxCodeMutation
{
    public function create(mixed $rootValue, array $request): TaxCode
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        $rates = isset($input['rates']) ? new DataCollection(TaxRateData::class, array_map(
            fn (array $rate): TaxRateData => new TaxRateData(
                name: (string) $rate['name'],
                rate: (float) $rate['rate'],
                effective_from: Carbon::parse((string) $rate['effective_from']),
                tax_account_id: isset($rate['tax_account_id']) ? (int) $rate['tax_account_id'] : null,
                effective_to: isset($rate['effective_to']) ? Carbon::parse((string) $rate['effective_to']) : null,
                sort_order: isset($rate['sort_order']) ? (int) $rate['sort_order'] : 0,
                metadata: $rate['metadata'] ?? null,
            ),
            $input['rates'],
        )) : null;

        return new CreateTaxCodeAction(
            data: new TaxCodeData(
                app: $app,
                company: $company,
                code: (string) $input['code'],
                name: (string) $input['name'],
                jurisdiction: $input['jurisdiction'] ?? null,
                is_active: $input['is_active'] ?? true,
                source: $input['source'] ?? 'kanvas',
                external_id: $input['external_id'] ?? null,
                metadata: $input['metadata'] ?? null,
                rates: $rates,
            ),
            user: $user,
        )->execute();
    }

    public function update(mixed $rootValue, array $request): TaxCode
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var TaxCode $taxCode */
        $taxCode = TaxCode::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdateTaxCodeAction(
            taxCode: $taxCode,
            data: new TaxCodeData(
                app: $app,
                company: $company,
                code: (string) $input['code'],
                name: (string) $input['name'],
                jurisdiction: $input['jurisdiction'] ?? null,
                is_active: $input['is_active'] ?? true,
                source: $input['source'] ?? 'kanvas',
                external_id: $input['external_id'] ?? null,
                metadata: $input['metadata'] ?? null,
            ),
            user: $user,
        )->execute();
    }
}
