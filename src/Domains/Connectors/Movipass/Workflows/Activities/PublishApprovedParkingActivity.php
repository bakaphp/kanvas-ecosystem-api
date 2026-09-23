<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as CorporateField;
use Kanvas\Connectors\Movipass\Actions\PublishParkingApplicationAction;
use Kanvas\Connectors\Movipass\Enums\ParkingApplicationFieldEnum as Field;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction]
class PublishApprovedParkingActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $lead, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            additionalParams: $params,
            integrationOperation: function ($lead) {
                if (empty(Field::PARKING_NAME->readFrom($lead))) {
                    return ['lead' => $lead->getId(), 'status' => 'skipped', 'reason' => 'not a parking application'];
                }

                if ((int) CorporateField::COMPANY_ID->readFrom($lead) === 0) {
                    return $this->failWorkflow([
                        'lead' => $lead->getId(),
                        'status' => 'failed',
                        'reason' => 'no company on the application',
                    ]);
                }

                try {
                    $product = new PublishParkingApplicationAction($lead)->execute();
                } catch (ValidationException $e) {
                    Field::STATUS_REASON->writeTo($lead, $e->getMessage());

                    return $this->failWorkflow([
                        'lead' => $lead->getId(),
                        'status' => 'failed',
                        'reason' => $e->getMessage(),
                    ]);
                }

                return [
                    'lead' => $lead->getId(),
                    'status' => 'published',
                    'product_id' => $product->getId(),
                ];
            },
            company: $lead->company,
        );
    }
}
