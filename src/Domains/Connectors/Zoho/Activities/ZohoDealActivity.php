<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Zoho\Actions\PushDealToZohoAction;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(
    name: 'Zoho Push Deal',
    description: 'Pushes the deal into Zoho CRM: creates the Zoho deal the first time, updates it after, '
        . 'links it to the Zoho lead it came from and uploads the files attached to the deal. Outbound '
        . 'one-way sync — it writes to Zoho, brings nothing back and does not contact the customer. '
        . 'A change that arrived from Zoho (the deal webhook) is skipped so it cannot bounce back out. '
        . 'Only useful if this company actually runs Zoho.',
    integration: IntegrationsEnum::ZOHO,
)]
class ZohoDealActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 3;

    #[Override]
    public function execute(Model $deal, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $deal instanceof Deal) {
            return $this->failWorkflow([
                'message' => 'Zoho Push Deal only runs on a Deal, got ' . $deal::class,
            ]);
        }

        return $this->executeIntegration(
            entity: $deal,
            app: $app,
            integration: IntegrationsEnum::ZOHO,
            additionalParams: $params,
            integrationOperation: function (Deal $deal, AppInterface $app, mixed $integrationCompany, array $additionalParams) {
                if (isset($additionalParams['zoho_payload'])) {
                    return [
                        'message' => 'Change originated in Zoho, nothing to push back',
                        'dealId' => $deal->getId(),
                    ];
                }

                $sync = $this->pushDealToZoho($deal);

                $result = [
                    'zohoDealId' => $sync['zohoDealId'],
                    'zohoRequest' => $sync['zohoRequest'],
                    'dealId' => $deal->getId(),
                    'files' => $sync['files'],
                ];

                if (empty($sync['zohoDealId'])) {
                    return $this->failWorkflow([
                        'message' => 'Deal was not created in Zoho, no Zoho deal id was returned',
                    ] + $result);
                }

                if (! $sync['recordExists']) {
                    return $this->failWorkflow([
                        'message' => 'Zoho deal no longer exists, nothing was updated',
                    ] + $result);
                }

                return $result;
            },
            company: $deal->company,
        );
    }

    protected function pushDealToZoho(Deal $deal): array
    {
        return new PushDealToZohoAction($deal)->execute();
    }
}
