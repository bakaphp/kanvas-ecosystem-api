<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Baka\Support\Str;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kanvas\Guild\Customers\Actions\CreatePeopleFromUserAction;
use Kanvas\Insurance\DataTransferObject\RegisteredInsurancePolicy;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceOrderOriginEnum;
use Kanvas\Insurance\Enums\InsuranceOrderTypeEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Insurance\Exceptions\InsuranceValidationException;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Insurance\Services\InsuranceOrderPipeline;
use Kanvas\Regions\Services\RegionResolutionService;
use Kanvas\Souk\Orders\Actions\CreateOrderAction;
use Kanvas\Souk\Orders\DataTransferObject\Order as OrderData;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;

class RegisterInsurancePolicyAction
{
    private const int LOCK_SECONDS = 30;

    public function __construct(
        private readonly RegisteredInsurancePolicy $policy,
    ) {
    }

    public function execute(): Order
    {
        try {
            return Cache::lock($this->lockKey(), self::LOCK_SECONDS)->block(
                self::LOCK_SECONDS,
                fn (): Order => $this->existingOrder() ?? $this->register()
            );
        } catch (LockTimeoutException) {
            throw new InsuranceValidationException('This policy is already being registered, try again');
        }
    }

    private function register(): Order
    {
        $this->assertOrderPipelineSeeded();

        $order = $this->createOrder();

        $order->set(InsuranceCustomFieldEnum::ORIGIN->value, InsuranceOrderOriginEnum::REGISTERED->value);
        $order->set(InsuranceCustomFieldEnum::POLICY_NUMBER->value, $this->policy->policyNumber);
        $order->set(InsuranceCustomFieldEnum::POLICY_ENDS_AT->value, $this->policy->endsAt);

        if ($this->policy->startsAt !== null) {
            $order->set(InsuranceCustomFieldEnum::POLICY_STARTS_AT->value, $this->policy->startsAt);
        }

        new RecordInsuranceStatusAction($order, InsuranceStatusEnum::POLICY_ACTIVE)->execute();

        return $order->fresh();
    }

    private function assertOrderPipelineSeeded(): void
    {
        if (InsuranceOrderPipeline::allowsRegistration($this->policy->app)) {
            return;
        }

        Log::error('Insurance order type cannot register policies for this app; run ' . InsuranceOrderPipeline::SETUP_COMMAND, [
            'app_id' => $this->policy->app->getId(),
        ]);

        throw new InsuranceValidationException('Policies cannot be registered right now, please try again later');
    }

    private function existingOrder(): ?Order
    {
        return Order::query()
            ->fromApp($this->policy->app)
            ->where('users_id', $this->policy->user->getId())
            ->where('reference', $this->reference())
            ->orderBy('id')
            ->first();
    }

    private function createOrder(): Order
    {
        $app = $this->policy->app;
        $user = $this->policy->user;
        $company = InsuranceProviderFactory::credentialCompany($app, $this->policy->company);
        $region = new RegionResolutionService($app)->forCompany($company)
            ?? throw new InsuranceValidationException('No region is configured to register insurance');

        return new CreateOrderAction(
            new OrderData(
                app: $app,
                region: $region,
                company: $company,
                people: new CreatePeopleFromUserAction(
                    $app,
                    $user->getCurrentBranch(),
                    $user
                )->execute(),
                user: $user,
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
                items: $this->orderItems(),
                email: $user->email,
                metadata: ['data' => $this->metadata()],
                fulfillmentStatus: OrderStatusEnum::PENDING->value,
                paymentGatewayName: ['manual'],
                reference: $this->reference(),
                orderType: InsuranceOrderTypeEnum::INSURANCE->value,
                paymentStatus: 'unpaid',
            )
        )->disableWorkflow()->execute();
    }

    private function orderItems(): array
    {
        return [
            [
                'id' => $this->policy->vehicle->getId(),
                'name' => $this->policy->vehicle->product->name,
                'quantity' => 1,
                'price' => 0.0,
            ],
        ];
    }

    private function metadata(): array
    {
        return [
            'insurer_name' => $this->policy->insurerName,
            'plan_name' => $this->policy->planName,
            'policy_number' => $this->policy->policyNumber,
            'starts_at' => $this->policy->startsAt,
            'ends_at' => $this->policy->endsAt,
        ];
    }

    private function reference(): string
    {
        return sprintf(
            'insurance-registered-%d-%s',
            $this->policy->vehicle->getId(),
            $this->policy->normalizedPolicyNumber()
        );
    }

    private function lockKey(): string
    {
        return sprintf(
            'insuranceRegisterPolicy-%d-%d-%s',
            $this->policy->app->getId(),
            $this->policy->user->getId(),
            $this->reference()
        );
    }
}
