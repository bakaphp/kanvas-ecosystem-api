<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Insurance\Actions\GenerateInsuranceCarnetAction;
use Kanvas\Insurance\Enums\InsuranceOrderOriginEnum;
use Kanvas\Insurance\Enums\InsuranceOrderTypeEnum;
use Kanvas\Insurance\Services\InsuranceCarnet;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

#[WorkflowAction]
class GenerateInsuranceCarnetActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        if (InsuranceOrderOriginEnum::isRegistered($order)) {
            return InsuranceOrderOriginEnum::purchaseFlowSkipped($order);
        }

        if (! InsuranceOrderTypeEnum::INSURANCE->isTypeOf($order) || ! InsuranceCarnet::hasIssuedPolicy($order)) {
            return $this->failWorkflow([
                'order' => $order->getId(),
                'message' => 'Order has no issued insurance policy to build a carnet for',
            ]);
        }

        if (InsuranceCarnet::url($order) !== null) {
            return [
                'order' => $order->getId(),
                'status' => 'skipped',
                'message' => 'Carnet was already generated',
            ];
        }

        try {
            $file = new GenerateInsuranceCarnetAction($order)->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->failWorkflow([
                'order' => $order->getId(),
                'message' => 'The carnet could not be generated: ' . $e->getMessage(),
            ]);
        }

        return [
            'order' => $order->getId(),
            'status' => 'success',
            'message' => 'Carnet generated',
            'carnetUrl' => $file->url,
        ];
    }
}
