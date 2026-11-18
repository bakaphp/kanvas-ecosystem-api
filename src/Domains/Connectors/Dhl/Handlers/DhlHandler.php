<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Handlers;

use Baka\Contracts\BaseIntegration;
use Baka\Contracts\DefaultRule;
use Kanvas\Connectors\Dhl\Actions\BuildDhlRatesPayloadAction;
use Kanvas\Connectors\Dhl\Activities\CreateDhlShipmentActivity;
use Kanvas\Connectors\Dhl\Activities\FlagCancelledDhlShipmentActivity;
use Kanvas\Connectors\Dhl\Client;
use Kanvas\Connectors\Dhl\DataTransferObject\Dhl;
use Kanvas\Connectors\Dhl\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Actions\EnsureDeliveryOrderNoticeRuleAction;
use Kanvas\Souk\Orders\Actions\SetupDeliveryOrderTypeAction;
use Kanvas\Souk\Orders\Enums\DeliveryOrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\DataTransferObject\RuleConditionData;
use Kanvas\Workflow\Rules\Enums\RuleConditionOperatorEnum;
use Override;
use Throwable;

class DhlHandler extends BaseIntegration
{
    public const string BOOKING_RULE_NAME = 'DHL create shipment on ready-for-dispatch';
    public const string CANCELLED_REVIEW_RULE_NAME = 'DHL flag cancelled booking for review';

    #[Override]
    public function setup(): bool
    {
        $dhl = Dhl::viaRequest(
            data: $this->data,
            app: $this->app,
            company: $this->company,
            region: $this->region
        );

        $this->company->set(ConfigurationEnum::BASE_URL->value, $dhl->baseUrl);
        $this->company->set(ConfigurationEnum::USERNAME->value, $dhl->username);
        $this->company->setEncrypted(ConfigurationEnum::PASSWORD->value, $dhl->password);
        $this->company->set(ConfigurationEnum::ACCOUNT_NUMBER->value, $dhl->accountNumber);
        $this->company->set(ConfigurationEnum::SIGNATURE_SERVICE->value, $dhl->signatureService);
        $this->company->set(ConfigurationEnum::LABEL_TEMPLATE->value, $dhl->labelTemplate);
        $this->company->set(ConfigurationEnum::ORIGIN->value, $dhl->origin);
        $this->company->set(ConfigurationEnum::REQUEST_PICKUP->value, $dhl->requestPickup ? 1 : 0);
        $this->company->set(ConfigurationEnum::PICKUP_CLOSE_TIME->value, $dhl->pickupCloseTime);
        $this->company->set(ConfigurationEnum::INCOTERM->value, $dhl->incoterm);

        if ($dhl->contentDescription !== null) {
            $this->company->set(ConfigurationEnum::CONTENT_DESCRIPTION->value, $dhl->contentDescription);
        }

        if (! $this->credentialsAreValid($dhl)) {
            return false;
        }

        new SetupDeliveryOrderTypeAction($this->app)->execute();
        new EnsureDeliveryOrderNoticeRuleAction($this->app, $this->company)->execute();

        return true;
    }

    #[Override]
    public function defaultRules(): array
    {
        return [
            new DefaultRule(
                trigger: WorkflowEnum::STATUS_TRANSITION,
                model: Order::class,
                activity: CreateDhlShipmentActivity::class,
                name: self::BOOKING_RULE_NAME,
                conditions: [
                    new RuleConditionData(
                        attribute_name: 'to_status',
                        operator: RuleConditionOperatorEnum::EQUAL,
                        value: DeliveryOrderStatusEnum::READY_FOR_DISPATCH->value,
                    ),
                ],
            ),
            new DefaultRule(
                trigger: WorkflowEnum::STATUS_TRANSITION,
                model: Order::class,
                activity: FlagCancelledDhlShipmentActivity::class,
                name: self::CANCELLED_REVIEW_RULE_NAME,
                conditions: [
                    new RuleConditionData(
                        attribute_name: 'to_status',
                        operator: RuleConditionOperatorEnum::EQUAL,
                        value: DeliveryOrderStatusEnum::CANCELLED->value,
                    ),
                ],
            ),
        ];
    }

    #[Override]
    protected function companySettingKeys(): array
    {
        return array_map(fn (ConfigurationEnum $key): string => $key->value, ConfigurationEnum::cases());
    }

    private function credentialsAreValid(Dhl $dhl): bool
    {
        $probe = new BuildDhlRatesPayloadAction(
            company: $this->company,
            parcels: [['weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 10]],
            destination: $dhl->origin,
        );

        try {
            new Client($this->company)->rates($probe->execute());

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
