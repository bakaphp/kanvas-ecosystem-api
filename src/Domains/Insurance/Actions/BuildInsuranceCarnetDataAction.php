<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Baka\Helpers\GenerateQrCode;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Insurance\DataTransferObject\InsuranceCustomer;
use Kanvas\Insurance\Enums\InsuranceCustomerFieldEnum;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\VehicleInsuranceAttributeEnum;
use Kanvas\Insurance\Models\InsurancePlan;
use Kanvas\Insurance\Services\VehicleAttributeReader;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Orders\Models\Order;
use Throwable;

class BuildInsuranceCarnetDataAction
{
    public function __construct(
        private readonly Order $order,
        private readonly bool $withQr = true,
    ) {
    }

    public function execute(): array
    {
        $policyNumber = (string) $this->order->get(InsuranceCustomFieldEnum::POLICY_NUMBER->value);
        $plan = $this->plan();
        $insurance = new ReadInsuranceOrderAction($this->order)->execute();

        return [
            'orderNumber' => (string) $this->order->order_number,
            'policyNumber' => $policyNumber,
            'insurer' => $plan?->insurer?->name ?? (string) $this->order->get(InsuranceCustomFieldEnum::PROVIDER->value),
            'plan' => $plan?->name,
            'insured' => $this->insured($insurance->customer),
            'vehicle' => $this->vehicle($insurance->vehicle?->product),
            'startsAt' => $this->date(InsuranceCustomFieldEnum::POLICY_STARTS_AT),
            'endsAt' => $this->date(InsuranceCustomFieldEnum::POLICY_ENDS_AT),
            'qr' => $this->withQr ? new GenerateQrCode($policyNumber)->asBase64() : null,
        ];
    }

    private function plan(): ?InsurancePlan
    {
        $planId = (int) $this->order->get(InsuranceCustomFieldEnum::PLAN_ID->value);

        return $planId > 0 ? InsurancePlan::query()->with('insurer')->find($planId) : null;
    }

    private function insured(?InsuranceCustomer $customer): string
    {
        $name = $customer !== null
            ? trim($customer->get(InsuranceCustomerFieldEnum::FIRST_NAME) . ' ' . $customer->get(InsuranceCustomerFieldEnum::LAST_NAME))
            : '';

        return $name !== '' ? $name : (string) $this->order->user?->fullName();
    }

    private function vehicle(?Products $product): array
    {
        if ($product === null) {
            return [];
        }

        $read = fn (VehicleInsuranceAttributeEnum $attribute): ?string => VehicleAttributeReader::read($product, $attribute);
        $description = trim(implode(' ', array_filter([
            $read(VehicleInsuranceAttributeEnum::BRAND),
            $read(VehicleInsuranceAttributeEnum::MODEL),
            $read(VehicleInsuranceAttributeEnum::YEAR),
        ])));

        return [
            'description' => $description,
            'plate' => $read(VehicleInsuranceAttributeEnum::PLATE),
        ];
    }

    private function date(InsuranceCustomFieldEnum $field): ?string
    {
        $value = Str::trimToNull((string) $this->order->get($field->value));

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('d/m/Y');
        } catch (Throwable) {
            return $value;
        }
    }
}
