<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Enums\InsuranceCatalogStatusEnum;
use Kanvas\Insurance\Enums\InsurancePaymentTermEnum;
use Kanvas\Insurance\Models\InsurancePlan;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Insurance\Services\InsuranceQuoteBinding;

class InsurancePlanSelection
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly InsurancePlan $plan,
        public readonly string $quoteNumber,
        public readonly ?int $vehicleVariantId = null,
        public readonly ?InsurancePaymentTermEnum $paymentTerm = null,
    ) {
    }

    public static function fromMultiple(
        AppInterface $app,
        UserInterface $user,
        CompanyInterface $company,
        array $data
    ): self {
        $credentialCompany = InsuranceProviderFactory::credentialCompany($app, $company);

        $plan = InsurancePlan::query()
            ->fromInsurerTenant($app, $credentialCompany)
            ->notDeleted()
            ->where('status', InsuranceCatalogStatusEnum::ACTIVE->value)
            ->whereHas('insurer', fn ($insurer) => $insurer->notDeleted()->where('status', InsuranceCatalogStatusEnum::ACTIVE->value))
            ->where('id', (int) $data['plan_id'])
            ->firstOrFail();

        $quoteNumber = Str::trimToNull((string) $data['quote_number'])
            ?? throw new ValidationException('A quote number is required');

        if (InsuranceQuoteBinding::planIdFor($app->getId(), $user->getId(), $quoteNumber) !== $plan->getId()) {
            throw new ValidationException('This quote was not issued to you for this plan');
        }

        return new self(
            app: $app,
            company: $company,
            user: $user,
            plan: $plan,
            quoteNumber: $quoteNumber,
            vehicleVariantId: InsuranceQuoteBinding::vehicleVariantIdFor($app->getId(), $user->getId(), $quoteNumber),
            paymentTerm: self::paymentTermFrom($data['payment_term'] ?? null),
        );
    }

    private static function paymentTermFrom(mixed $value): ?InsurancePaymentTermEnum
    {
        $term = Str::trimToNull($value === null ? null : (string) $value);

        if ($term === null) {
            return null;
        }

        return InsurancePaymentTermEnum::tryFrom($term) ?? throw new ValidationException('This payment term is not available');
    }
}
