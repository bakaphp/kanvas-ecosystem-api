<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Contracts\DefaultRule;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Activities\NotifyDeliveryOrderStatusActivity;
use Kanvas\Souk\Orders\Enums\DeliveryOrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Actions\EnsureDefaultRuleAction;
use Kanvas\Workflow\Rules\DataTransferObject\RuleConditionData;
use Kanvas\Workflow\Rules\Enums\RuleConditionOperatorEnum;
use Kanvas\Workflow\Rules\Models\Rule;

class EnsureDeliveryOrderNoticeRuleAction
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
    ) {
    }

    public function execute(): ?Rule
    {
        return new EnsureDefaultRuleAction(
            $this->defaultRule(),
            $this->app,
            $this->company,
            $this->company->user,
            'Created by the delivery order type setup',
        )->execute();
    }

    private function defaultRule(): DefaultRule
    {
        return new DefaultRule(
            trigger: WorkflowEnum::STATUS_TRANSITION,
            model: Order::class,
            activity: NotifyDeliveryOrderStatusActivity::class,
            name: NotifyDeliveryOrderStatusActivity::RULE_NAME,
            conditions: [
                new RuleConditionData(
                    attribute_name: 'to_status',
                    operator: RuleConditionOperatorEnum::IN,
                    value: implode(',', array_map(
                        fn (DeliveryOrderStatusEnum $status): string => $status->value,
                        NotifyDeliveryOrderStatusActivity::NOTIFIED_STATUSES
                    )),
                ),
            ],
        );
    }
}
