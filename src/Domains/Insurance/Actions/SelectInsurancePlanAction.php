<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\UpdateLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\DataTransferObject\LeadUpdateInput;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Insurance\DataTransferObject\InsurancePlanSelection;
use Kanvas\Insurance\DataTransferObject\QuoteResult;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Insurance\Services\InsuranceCommissionCalculator;
use Kanvas\Insurance\Services\InsuranceQuoteSignature;

class SelectInsurancePlanAction
{
    private const LOCK_SECONDS = 15;

    public function __construct(
        private readonly InsurancePlanSelection $selection,
    ) {
    }

    public function execute(): Lead
    {
        $quote = $this->confirmedQuote();

        try {
            return Cache::lock($this->lockKey(), self::LOCK_SECONDS)->block(
                self::LOCK_SECONDS,
                fn (): Lead => $this->createOrUpdateLead($quote)
            );
        } catch (LockTimeoutException) {
            throw new ValidationException('Another selection of this plan is in progress, try again');
        }
    }

    private function confirmedQuote(): QuoteResult
    {
        $insurer = $this->selection->plan->insurer;
        $company = InsuranceProviderFactory::credentialCompany($this->selection->app, $this->selection->company);

        $quote = InsuranceProviderFactory::make($insurer->provider, $this->selection->app, $company)
            ->getQuote($this->selection->quoteNumber);

        if (! $quote->success) {
            throw new ValidationException('The quote is no longer valid');
        }

        if ($quote->productCode !== null && $quote->productCode !== $this->selection->plan->external_code) {
            throw new ValidationException('This quote was issued for a different plan');
        }

        return $quote;
    }

    private function createOrUpdateLead(QuoteResult $quote): Lead
    {
        $customFields = $this->customFields($quote);
        $existing = $this->existingLead();

        if ($existing === null) {
            return new CreateLeadAction($this->newLeadData($customFields))->execute();
        }

        return new UpdateLeadAction(
            $existing,
            new LeadUpdateInput(
                branch_id: (int) $existing->companies_branches_id,
                title: $existing->title,
                people_id: (int) $existing->people_id,
                custom_fields: $customFields,
            ),
            $this->selection->user,
        )->execute();
    }

    private function existingLead(): ?Lead
    {
        $people = $this->customerPeople();

        if ($people === null) {
            return null;
        }

        return Lead::getByCustomFieldBuilderTransactionSafe(
            InsuranceCustomFieldEnum::PLAN_ID->value,
            (string) $this->selection->plan->getId(),
            $this->selection->company,
        )
            ->fromApp($this->selection->app)
            ->fromCompany($this->selection->company)
            ->notDeleted()
            ->hasOpenLeadStatus($this->selection->company)
            ->where('people_id', $people->getId())
            ->orderByDesc('id')
            ->first();
    }

    private function customerPeople(): ?People
    {
        return PeoplesRepository::getByEmail(
            (string) $this->selection->user->email,
            $this->selection->company,
            $this->selection->app,
        );
    }

    private function newLeadData(array $customFields): LeadData
    {
        $user = $this->selection->user;
        $plan = $this->selection->plan;
        $contacts = array_values(array_filter([
            ['value' => (string) $user->email],
            $user->phone_number ? ['value' => (string) $user->phone_number] : null,
        ]));

        return LeadData::fromMultiple(
            $user,
            $this->selection->app,
            [
                'title' => $plan->insurer->name . ' - ' . $plan->name,
                'people' => [
                    'firstname' => (string) $user->firstname,
                    'lastname' => (string) $user->lastname,
                    'contacts' => $contacts,
                ],
                'custom_fields' => $customFields,
            ]
        );
    }

    private function customFields(QuoteResult $quote): array
    {
        $plan = $this->selection->plan;

        return array_filter([
            InsuranceCustomFieldEnum::PROVIDER->value => $plan->insurer->provider,
            InsuranceCustomFieldEnum::PLAN_ID->value => (string) $plan->getId(),
            InsuranceCustomFieldEnum::PRODUCT_CODE->value => $plan->external_code,
            InsuranceCustomFieldEnum::QUOTE_NUMBER->value => $quote->quoteNumber,
            InsuranceCustomFieldEnum::PREMIUM->value => $quote->premium,
            InsuranceCustomFieldEnum::RATE_PER_KM->value => $quote->ratePerKm,
            InsuranceCustomFieldEnum::TAX->value => $quote->tax,
            InsuranceCustomFieldEnum::TOTAL->value => $quote->total,
            InsuranceCustomFieldEnum::COMMISSION->value => InsuranceCommissionCalculator::effective(
                $quote,
                (float) $plan->commission_rate
            ),
            InsuranceCustomFieldEnum::CAN_EMIT->value => $quote->canEmit === null ? null : (int) $quote->canEmit,
            InsuranceCustomFieldEnum::INSPECTION_STATUS->value => $quote->inspectionStatus,
            InsuranceCustomFieldEnum::QUOTE_SIGNATURE->value => InsuranceQuoteSignature::sign(
                userId: $this->selection->user->getId(),
                planId: $plan->getId(),
                provider: $plan->insurer->provider,
                quoteNumber: $quote->quoteNumber,
                total: (float) $quote->total,
                tax: (float) $quote->tax,
            ),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function lockKey(): string
    {
        return sprintf(
            'insuranceSelect-%d-%d-%d',
            $this->selection->app->getId(),
            $this->selection->user->getId(),
            $this->selection->plan->getId()
        );
    }
}
