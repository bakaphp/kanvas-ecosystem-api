<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\PaymentTerms;

use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\PaymentTerms\Actions\CreatePaymentTermAction;
use Kanvas\Scribe\PaymentTerms\Actions\UpdatePaymentTermAction;
use Kanvas\Scribe\PaymentTerms\DataTransferObject\PaymentTermData;
use Kanvas\Scribe\PaymentTerms\Models\PaymentTerm;

class PaymentTermMutation
{
    public function create(mixed $rootValue, array $request): PaymentTerm
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        return new CreatePaymentTermAction(
            data: new PaymentTermData(
                app: $app,
                company: $company,
                name: (string) $input['name'],
                net_days: (int) $input['net_days'],
                discount_days: isset($input['discount_days']) ? (int) $input['discount_days'] : null,
                discount_pct: isset($input['discount_pct']) ? (float) $input['discount_pct'] : null,
                is_default: (bool) ($input['is_default'] ?? false),
                metadata: $input['metadata'] ?? null,
            ),
            user: $user,
        )->execute();
    }

    public function update(mixed $rootValue, array $request): PaymentTerm
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var PaymentTerm $term */
        $term = PaymentTerm::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdatePaymentTermAction(
            paymentTerm: $term,
            data: new PaymentTermData(
                app: $app,
                company: $company,
                name: (string) $input['name'],
                net_days: (int) $input['net_days'],
                discount_days: isset($input['discount_days']) ? (int) $input['discount_days'] : null,
                discount_pct: isset($input['discount_pct']) ? (float) $input['discount_pct'] : null,
                is_default: (bool) ($input['is_default'] ?? false),
                metadata: $input['metadata'] ?? null,
            ),
            user: $user,
        )->execute();
    }
}
