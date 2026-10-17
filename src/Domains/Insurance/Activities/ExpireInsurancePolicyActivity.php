<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceOrderStatusEnum;
use Kanvas\Insurance\Enums\InsuranceOrderTypeEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction]
class ExpireInsurancePolicyActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        if (! InsuranceOrderTypeEnum::INSURANCE->isTypeOf($order)
            || ($params['to_status'] ?? null) !== InsuranceOrderStatusEnum::EXPIRED->value
        ) {
            return [
                'order' => $order->getId(),
                'status' => 'skipped',
                'message' => 'Order did not expire as an insurance policy',
            ];
        }

        $order->set(InsuranceCustomFieldEnum::STATUS->value, InsuranceStatusEnum::EXPIRED->value);

        return [
            'order' => $order->getId(),
            'status' => 'success',
        ];
    }
}
