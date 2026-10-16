<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Insurance\Actions\SendInsurancePolicyEmailAction;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Exceptions\InsuranceValidationException;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction]
class SendInsurancePolicyEmailActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        if ((string) $order->get(InsuranceCustomFieldEnum::POLICY_EMAILED_AT->value) !== '') {
            return [
                'order' => $order->getId(),
                'status' => 'skipped',
                'message' => 'Policy email already sent',
            ];
        }

        try {
            new SendInsurancePolicyEmailAction($order)->execute();
        } catch (InsuranceValidationException $e) {
            return $this->failWorkflow([
                'order' => $order->getId(),
                'message' => $e->getMessage(),
            ]);
        }

        return [
            'order' => $order->getId(),
            'status' => 'success',
            'message' => 'Policy email sent',
        ];
    }
}
