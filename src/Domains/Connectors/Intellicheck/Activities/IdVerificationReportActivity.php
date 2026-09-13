<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intellicheck\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Connectors\Intellicheck\Actions\VerifyPeopleIdAction;
use Kanvas\Connectors\Intellicheck\Jobs\AttachDriverLicenseImagesJob;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction]
class IdVerificationReportActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 3;

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::INTELLICHECK,
            integrationOperation: function (Model $lead) use ($params): array {
                if (! $lead instanceof Lead) {
                    return $this->failWorkflow(['message' => 'ID verification only runs on a lead']);
                }

                $people = $this->resolveVerifiedPeople($lead, $params);

                // The caller writes the licence base64 onto the person a few seconds after firing this verb.
                sleep(20);

                $images = $people->get('driver_license_images');

                $result = new VerifyPeopleIdAction($people, $lead)->execute(
                    verificationData: $params,
                    images: is_array($images) ? $images : null,
                    reuseExistingEngagement: true,
                );

                $this->attachLateDriverLicenseImages($lead, $people, $result);

                return $result;
            },
            additionalParams: $params,
            company: $entity->company,
        );
    }

    /**
     * A co-buyer scan carries its own id in `participant.peopleId`; resolved tenant-scoped so its result
     * never lands on the main buyer.
     */
    private function resolveVerifiedPeople(Lead $lead, array $params): People
    {
        $participantPeopleId = $params['participant']['peopleId'] ?? null;

        if ($participantPeopleId === null) {
            return $lead->people;
        }

        try {
            return People::getByIdFromCompanyApp((int) $participantPeopleId, $lead->company, $lead->app);
        } catch (ModelNotFoundException) {
            return $lead->people;
        }
    }

    /**
     * @deprecated Images that land after the 20s wait still reach the folder through the delayed job,
     *             which also clears `driver_license_images`. Delete with `after-id-verification`.
     */
    private function attachLateDriverLicenseImages(Lead $lead, People $people, array $result): void
    {
        if (($result['engagement_id'] ?? null) === null) {
            return;
        }

        $engagement = Engagement::getByIdFromCompanyApp(
            (int) $result['engagement_id'],
            $lead->company,
            $lead->app
        );

        AttachDriverLicenseImagesJob::dispatch(
            $lead->app,
            $people,
            $engagement->message,
            (string) ($result['data']['status'] ?? 'unknown'),
            (string) ($result['data']['message'] ?? ''),
        )->delay(now()->addSeconds(30));
    }
}
