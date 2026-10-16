<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Exceptions\InsuranceValidationException;
use Kanvas\Insurance\Notifications\InsurancePolicyIssuedNotification;
use Kanvas\Insurance\Services\InsuranceCarnet;
use Kanvas\Souk\Orders\Models\Order;

class SendInsurancePolicyEmailAction
{
    public function __construct(
        private readonly Order $order,
    ) {
    }

    public function execute(): void
    {
        $carnetUrl = InsuranceCarnet::url($this->order);

        if (! InsuranceCarnet::hasIssuedPolicy($this->order)) {
            throw new InsuranceValidationException('This order has no issued policy yet.');
        }

        if ($carnetUrl === null) {
            throw new InsuranceValidationException('The carnet for this policy is not ready yet. Please try again in a few minutes.');
        }

        $user = $this->order->user;

        if ($user === null) {
            throw new InsuranceValidationException('This order has no customer to email.');
        }

        $user->notify(new InsurancePolicyIssuedNotification(
            $this->order,
            new BuildInsuranceCarnetDataAction($this->order, withQr: false)->execute(),
            $carnetUrl,
        ));

        $this->order->set(InsuranceCustomFieldEnum::POLICY_EMAILED_AT->value, now()->toIso8601String());
    }
}
