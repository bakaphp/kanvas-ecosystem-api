<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Salesforce\Actions\PushOrganizationAction;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

/**
 * Organization mirror of ResolveDuplicatePeopleActivity — see that class for the full rationale.
 */
#[WorkflowAction(name: 'SalesforceResolveDuplicateOrganizationActivity')]
class ResolveDuplicateOrganizationActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 3;

    /**
     * @param Organization $target
     */
    #[Override]
    public function execute(Model $target, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $sourceId = (int) ($params['source_id'] ?? 0);
        if ($sourceId === 0) {
            return ['skipped' => 'missing source_id'];
        }

        /** @var Organization|null $source */
        $source = Organization::query()->where('id', $sourceId)->first();
        if ($source === null) {
            return ['skipped' => 'source not found'];
        }

        $sourceExternalId = $source->get(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value);
        if ($sourceExternalId === null) {
            return ['skipped' => 'source has no salesforce_account_id'];
        }

        try {
            new PushOrganizationAction($target)->execute();

            $targetExternalId = $target->get(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value);
            if ((string) $targetExternalId === (string) $sourceExternalId) {
                return ['pushed' => true, 'deleted_source_account' => null];
            }

            Client::getInstance($app, $target->company)->delete('Account', (string) $sourceExternalId);

            return ['pushed' => true, 'deleted_source_account' => (string) $sourceExternalId];
        } catch (Throwable $e) {
            report($e);

            return ['error' => $e->getMessage()];
        }
    }
}
