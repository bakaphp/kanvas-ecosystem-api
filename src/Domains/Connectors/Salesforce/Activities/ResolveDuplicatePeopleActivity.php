<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Salesforce\Actions\PushPeopleAction;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

/**
 * Reacts to WorkflowEnum::AFTER_MERGE, fired by MergePeopleAction on the surviving target. Pushes
 * the target so Salesforce gets the up-to-date name/contacts. If the source's own Contact Id
 * survived the merge untouched (a real conflict — the target already had its own, so nothing was
 * adopted), also deletes the source's Contact (Recycle Bin, not a permanent delete). If the
 * target's id now equals the source's (the target had none and adopted it), there's only one
 * Contact in Salesforce already — nothing to delete.
 *
 * Only wired to run at all for companies with a Rule for this event + entity — no-ops here too
 * when the source never had a Salesforce Contact Id.
 */
#[WorkflowAction(name: 'SalesforceResolveDuplicatePeopleActivity')]
class ResolveDuplicatePeopleActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 3;

    /**
     * @param People $target
     */
    #[Override]
    public function execute(Model $target, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $sourceId = (int) ($params['source_id'] ?? 0);
        if ($sourceId === 0) {
            return ['skipped' => 'missing source_id'];
        }

        /** @var People|null $source */
        $source = People::query()->where('id', $sourceId)->first();
        if ($source === null) {
            return ['skipped' => 'source not found'];
        }

        $sourceExternalId = $source->get(CustomFieldEnum::SALESFORCE_CONTACT_ID->value);
        if ($sourceExternalId === null) {
            return ['skipped' => 'source has no salesforce_contact_id'];
        }

        try {
            new PushPeopleAction($target)->execute();

            $targetExternalId = $target->get(CustomFieldEnum::SALESFORCE_CONTACT_ID->value);
            if ((string) $targetExternalId === (string) $sourceExternalId) {
                return ['pushed' => true, 'deleted_source_contact' => null];
            }

            Client::getInstance($app, $target->company)->delete('Contact', (string) $sourceExternalId);

            return ['pushed' => true, 'deleted_source_contact' => (string) $sourceExternalId];
        } catch (Throwable $e) {
            report($e);

            return ['error' => $e->getMessage()];
        }
    }
}
