<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Insurance\Contracts\CustomerDataProviderInterface;
use Kanvas\Insurance\Contracts\InsuranceProviderInterface;
use Kanvas\Insurance\DataTransferObject\InsuranceCustomer;
use Kanvas\Insurance\DataTransferObject\InsuranceLeadContract;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Insurance\DataTransferObject\QuoteResult;
use Kanvas\Insurance\Enums\InsuranceCatalogStatusEnum;
use Kanvas\Insurance\Enums\InsuranceCustomerFieldEnum;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceOrderTypeEnum;
use Kanvas\Insurance\Models\InsurancePlan;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Insurance\Services\InsuranceCommissionCalculator;
use Kanvas\Insurance\Services\InsuranceOrderPipeline;
use Kanvas\Insurance\Services\InsuranceProductSlug;
use Kanvas\Insurance\Services\InsuranceQuoteSignature;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Regions\Services\RegionResolutionService;
use Kanvas\Souk\Orders\Actions\CreateOrderAction;
use Kanvas\Souk\Orders\DataTransferObject\Order as OrderData;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\WorkflowEnum;

class ConvertInsuranceLeadToOrderAction
{
    private const LOCK_SECONDS = 30;

    private const WON_STATUS_NAME = 'Won';

    public function __construct(
        private readonly InsuranceLeadContract $contract,
    ) {
    }

    public function execute(): Order
    {
        try {
            return Cache::lock($this->lockKey(), self::LOCK_SECONDS)->block(
                self::LOCK_SECONDS,
                fn (): Order => $this->convert()
            );
        } catch (LockTimeoutException) {
            throw new ValidationException('This quote is already being processed, try again');
        }
    }

    private function convert(): Order
    {
        $lead = $this->contract->lead->fresh() ?? throw new ValidationException('Quote not found');

        $existing = $this->existingOrder($lead);

        if ($existing !== null) {
            return $this->finishLead($lead, $existing, $this->wonStatus());
        }

        if (! $lead->hasOpenLeadStatus()) {
            throw new ValidationException('This quote is closed and can no longer be contracted');
        }

        $this->assertOrderPipelineSeeded();

        $plan = $this->planOf($lead);
        $this->assertUntampered($lead, $plan);

        $provider = InsuranceProviderFactory::make(
            $plan->insurer->provider,
            $this->contract->app,
            $this->credentialCompany()
        );
        $customer = $this->confirmedCustomer($lead, $provider);
        $variant = $this->variantFor($plan);
        $wonStatus = $this->wonStatus();
        $region = $this->region();
        $quote = $this->attachedQuote($lead, $plan, $provider, $customer);

        $order = $this->createOrder($lead, $plan, $provider, $customer, $variant, $region, $quote);

        return $this->finishLead($lead, $order, $wonStatus);
    }

    private function assertOrderPipelineSeeded(): void
    {
        if (InsuranceOrderPipeline::isSeeded($this->contract->app)) {
            return;
        }

        Log::error('Insurance order type is not set up for this app; run ' . InsuranceOrderPipeline::SETUP_COMMAND, [
            'app_id' => $this->contract->app->getId(),
            'lead_id' => $this->contract->lead->getId(),
        ]);

        throw new ValidationException('Insurance purchases are not available right now, please try again later');
    }

    private function existingOrder(Lead $lead): ?Order
    {
        return Order::query()
            ->fromApp($this->contract->app)
            ->where('users_id', $this->contract->user->getId())
            ->where('reference', $this->referenceFor($lead))
            ->orderBy('id')
            ->first();
    }

    private function finishLead(Lead $lead, Order $order, LeadStatus $wonStatus): Order
    {
        $lead->set(InsuranceCustomFieldEnum::ORDER_ID->value, $order->getId());
        $lead->leads_status_id = $wonStatus->getId();
        $lead->saveOrFail();

        return $order;
    }

    private function planOf(Lead $lead): InsurancePlan
    {
        $planId = (int) $lead->get(InsuranceCustomFieldEnum::PLAN_ID->value);

        if ($planId === 0 || $this->quoteNumberOf($lead) === null) {
            throw new ValidationException('This lead is not an insurance quote');
        }

        $plan = InsurancePlan::query()
            ->fromInsurerTenant($this->contract->app, $this->credentialCompany())
            ->notDeleted()
            ->where('status', InsuranceCatalogStatusEnum::ACTIVE->value)
            ->whereHas('insurer', fn ($insurer) => $insurer->notDeleted()->where('status', InsuranceCatalogStatusEnum::ACTIVE->value))
            ->where('id', $planId)
            ->first();

        if ($plan === null || $plan->insurer->provider !== (string) $lead->get(InsuranceCustomFieldEnum::PROVIDER->value)) {
            throw new ValidationException('This plan is no longer available');
        }

        return $plan;
    }

    private function assertUntampered(Lead $lead, InsurancePlan $plan): void
    {
        $signed = InsuranceQuoteSignature::matches(
            signature: (string) $lead->get(InsuranceCustomFieldEnum::QUOTE_SIGNATURE->value),
            userId: (int) $lead->users_id,
            planId: $plan->getId(),
            provider: $plan->insurer->provider,
            quoteNumber: (string) $this->quoteNumberOf($lead),
            total: (float) $lead->get(InsuranceCustomFieldEnum::TOTAL->value),
            tax: (float) $lead->get(InsuranceCustomFieldEnum::TAX->value),
        );

        if (! $signed) {
            throw new ValidationException('This quote is no longer valid, please select the plan again');
        }
    }

    private function quoteNumberOf(Lead $lead): ?string
    {
        return Str::trimToNull((string) $lead->get(InsuranceCustomFieldEnum::QUOTE_NUMBER->value));
    }

    private function confirmedCustomer(Lead $lead, InsuranceProviderInterface $provider): InsuranceCustomer
    {
        $this->persistSupplied($lead);

        $customer = new ResolveInsuranceCustomerAction($lead)->execute();

        if (! $provider instanceof CustomerDataProviderInterface) {
            return $customer;
        }

        $missing = $customer->missing($provider->requiredCustomerFields());

        if ($missing !== []) {
            throw new ValidationException(
                'We need more information to issue this policy: ' . implode(
                    ', ',
                    array_map(fn (InsuranceCustomerFieldEnum $field): string => $field->label(), $missing)
                )
            );
        }

        return $customer;
    }

    private function persistSupplied(Lead $lead): void
    {
        foreach (InsuranceCustomerFieldEnum::cases() as $field) {
            $value = $this->contract->suppliedCustomer->get($field);

            if ($value !== null) {
                $lead->set($field->customFieldKey(), $value);
            }
        }
    }

    private function attachedQuote(
        Lead $lead,
        InsurancePlan $plan,
        InsuranceProviderInterface $provider,
        InsuranceCustomer $customer
    ): QuoteResult {
        $quote = new PushCustomerToQuoteAction(
            $provider,
            (string) $this->quoteNumberOf($lead),
            $provider instanceof CustomerDataProviderInterface
                ? new InsuranceQuoteRequest(
                    product: (string) $plan->external_code,
                    payload: ['cliente' => $provider->customerBlock($customer)]
                )
                : null
        )->execute();

        if (! $quote->success) {
            throw new ValidationException('The quote is no longer valid');
        }

        if ($quote->productCode !== null && $quote->productCode !== $plan->external_code) {
            throw new ValidationException('This quote was issued for a different plan');
        }

        if (($quote->total ?? 0) <= 0) {
            throw new ValidationException('This quote carries no price');
        }

        return $quote;
    }

    private function variantFor(InsurancePlan $plan): Variants
    {
        $insurerCompanyId = (int) $this->credentialCompany()->get(InsuranceCustomFieldEnum::INSURER_COMPANY_ID->value);

        $product = Products::query()
            ->where('apps_id', $this->contract->app->getId())
            ->where('slug', InsuranceProductSlug::for($plan->insurer->provider, (string) $plan->external_code))
            ->where('is_published', 1)
            ->notDeleted()
            ->orderByRaw('companies_id = ? desc', [$insurerCompanyId])
            ->first();

        $variant = $product?->variants()->notDeleted()->first();

        return $variant ?? throw new ValidationException('This plan is not available for purchase right now');
    }

    private function wonStatus(): LeadStatus
    {
        return LeadStatus::query()
            ->where('name', self::WON_STATUS_NAME)
            ->whereIn('apps_id', [0, $this->contract->app->getId()])
            ->whereIn('companies_id', [0, $this->contract->company->getId()])
            ->orderByDesc('companies_id')
            ->orderByDesc('apps_id')
            ->first() ?? throw new ValidationException('The quote cannot be closed right now');
    }

    private function region(): Regions
    {
        return new RegionResolutionService($this->contract->app)->forCompany($this->credentialCompany())
            ?? throw new ValidationException('No region is configured to sell insurance');
    }

    private function createOrder(
        Lead $lead,
        InsurancePlan $plan,
        InsuranceProviderInterface $provider,
        InsuranceCustomer $customer,
        Variants $variant,
        Regions $region,
        QuoteResult $quote,
    ): Order {
        $app = $this->contract->app;

        $order = new CreateOrderAction(
            new OrderData(
                app: $app,
                region: $region,
                company: $this->credentialCompany(),
                people: $lead->people,
                user: $this->contract->user,
                token: Str::random(32),
                orderNumber: '',
                shippingAddress: null,
                billingAddress: null,
                total: 0.0,
                taxes: 0.0,
                totalDiscount: 0.0,
                totalShipping: 0.0,
                status: OrderStatusEnum::PENDING->value,
                checkoutToken: '',
                currency: $region->currency,
                items: [$this->orderItem($variant, $plan, $quote)],
                email: $customer->get(InsuranceCustomerFieldEnum::EMAIL),
                metadata: ['insurance' => $this->insuranceMetadata($lead, $plan, $provider, $customer, $quote)],
                fulfillmentStatus: OrderStatusEnum::PENDING->value,
                paymentGatewayName: ['manual'],
                reference: $this->referenceFor($lead),
                orderType: InsuranceOrderTypeEnum::INSURANCE->value,
                paymentStatus: 'unpaid',
            )
        )->disableWorkflow()->execute();

        new StampInsuranceQuoteAction(
            order: $order,
            providerName: $provider->name(),
            productCode: (string) $plan->external_code,
            quote: $quote,
            commission: InsuranceCommissionCalculator::effective($quote, (float) $plan->commission_rate),
        )->execute();
        $order->set(InsuranceCustomFieldEnum::LEAD_ID->value, $lead->getId());
        $order->set(InsuranceCustomFieldEnum::PLAN_ID->value, (string) $plan->getId());

        $order->fireWorkflow(
            event: WorkflowEnum::CREATED->value,
            async: true,
            params: ['app' => $app]
        );

        return $order;
    }

    private function orderItem(Variants $variant, InsurancePlan $plan, QuoteResult $quote): array
    {
        return [
            'id' => $variant->getId(),
            'name' => $plan->insurer->name . ' - ' . $plan->name,
            'quantity' => 1,
            'price' => (float) $quote->total,
            'tax' => (float) $quote->tax,
        ];
    }

    private function insuranceMetadata(
        Lead $lead,
        InsurancePlan $plan,
        InsuranceProviderInterface $provider,
        InsuranceCustomer $customer,
        QuoteResult $quote
    ): array {
        $metadata = [
            'provider' => $provider->name(),
            'product' => $plan->external_code,
            'quote_number' => $quote->quoteNumber,
            'plan_id' => $plan->getId(),
            'lead_id' => $lead->getId(),
        ];

        if ($provider instanceof CustomerDataProviderInterface) {
            $metadata['cliente'] = $provider->customerBlock($customer);
        }

        return $metadata;
    }

    private function referenceFor(Lead $lead): string
    {
        return 'insurance-lead-' . $lead->getId();
    }

    private function credentialCompany(): CompanyInterface
    {
        return InsuranceProviderFactory::credentialCompany($this->contract->app, $this->contract->company);
    }

    private function lockKey(): string
    {
        return sprintf(
            'insuranceContract-%d-%d',
            $this->contract->app->getId(),
            $this->contract->lead->getId()
        );
    }
}
