<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intellicheck\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Connectors\Intellicheck\Actions\VerifyPeopleIdAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

#[WorkflowAction(
    name: 'Generate ID Verification',
    description: 'Scores an Intellicheck ID-verification payload and attaches the report to the '
        . 'engagement the scan came from, so it lands in the folder that already holds the licence '
        . 'images instead of a new one. Shared entry point for web, mobile and the Intellicheck receiver.',
    integration: IntegrationsEnum::INTELLICHECK,
    params: [
        'eid' => 'Id or uuid of the ID-verification engagement the scan belongs to. Send it whenever '
            . 'the caller knows it — the report then threads under that message instead of relying on '
            . "the person's newest submitted engagement. Mobile does not have it.",
        'people_uuid' => 'Uuid of the person verified. Omit for the main buyer; a co-buyer scan must '
            . 'send it or the result lands on the main buyer.',
        'intellicheck' => 'The Intellicheck result, unwrapped: idcheck / OCR / ocr_match / facial / ipqs.',
        'images' => 'Optional {front, back, face}. Each side is either a base64 image or the uuid of a '
            . 'file already uploaded to filesystem. Any side left out falls back to the '
            . "person's driver_license_images custom field; `face` falls back to the payload's "
            . 'facial.data.photoFace, which is the only place a selfie ever comes from.',
    ],
    requiredParams: ['intellicheck'],
)]
class GenerateIdVerificationActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTELLICHECK,
            additionalParams: $params,
            integrationOperation: function (Lead $lead) use ($params): array {
                $verificationData = $params['intellicheck'] ?? [];

                if (! is_array($verificationData) || $verificationData === []) {
                    return $this->failWorkflow(['message' => 'No Intellicheck payload to score']);
                }

                // Mobile sends no engagement reference at all, so a missing `eid` is a normal call: the
                // action then resolves the person's existing submitted engagement. An `eid` that is
                // present but does not resolve is a different thing — that one is a caller bug.
                $engagement = null;

                if (($params['eid'] ?? null) !== null && $params['eid'] !== '') {
                    $engagement = $this->resolveEngagement($lead, $params);

                    if ($engagement === null) {
                        return $this->failWorkflow([
                            'message' => 'Engagement ' . $params['eid'] . ' not found for this lead',
                        ]);
                    }
                }

                $people = $this->resolvePeople($lead, $engagement, $params);

                if ($people === null) {
                    return $this->failWorkflow(['message' => 'No person to attach the verification to']);
                }

                return new VerifyPeopleIdAction($people, $lead)->execute(
                    verificationData: $verificationData,
                    parentEngagement: $engagement,
                    images: $this->resolveImages($params),
                    reuseExistingEngagement: true,
                );
            },
            company: $lead->company,
        );
    }

    /**
     * Tenant-scoped on purpose: `eid` comes from an external caller, so an unscoped lookup would read
     * across companies. The lead check catches a stale or copy-pasted id pointing at another lead's
     * engagement, which would otherwise attach this report to a stranger's folder.
     */
    private function resolveEngagement(Lead $lead, array $params): ?Engagement
    {
        $eid = $params['eid'] ?? null;

        if ($eid === null || $eid === '') {
            return null;
        }

        try {
            $engagement = Str::isUuid((string) $eid)
                ? Engagement::getByUuidFromCompanyApp((string) $eid, $lead->company, $lead->app)
                : Engagement::getByIdFromCompanyApp((int) $eid, $lead->company, $lead->app);
        } catch (Throwable) {
            return null;
        }

        return (int) $engagement->leads_id === $lead->getId() ? $engagement : null;
    }

    /**
     * A co-buyer scan names its own person; the engagement's `people_id` is the fallback because a
     * participant's engagement already carries it, and only then the lead's main people.
     */
    private function resolvePeople(Lead $lead, ?Engagement $engagement, array $params): ?People
    {
        $peopleUuid = $params['people_uuid'] ?? null;

        if ($peopleUuid !== null && $peopleUuid !== '') {
            try {
                return People::getByUuidFromCompanyApp((string) $peopleUuid, $lead->company, $lead->app);
            } catch (Throwable) {
                // Fall through to the engagement's own person.
            }
        }

        if ($engagement?->people_id !== null && (int) $engagement->people_id !== (int) $lead->people_id) {
            $participant = People::fromCompany($lead->company)
                ->fromApp($lead->app)
                ->where('id', (int) $engagement->people_id)
                ->notDeleted()
                ->first();

            if ($participant !== null) {
                return $participant;
            }
        }

        return $lead->people;
    }

    /**
     * Values pass through as-is: `VerifyPeopleIdAction::resolveFile()` decides whether a side is a
     * base64 image or a filesystem uuid, so both callers can send whichever they hold.
     *
     * `face` has no caller-side source: a selfie only ever exists inside the Intellicheck payload, as
     * `facial.data.photoFace`. Front and back are the opposite — a showroom scan writes them to the
     * person's custom field, so leaving them out here is normal and the action falls back to it.
     *
     * @return array{front: ?string, back: ?string, face: ?string}
     */
    private function resolveImages(array $params): array
    {
        $images = is_array($params['images'] ?? null) ? $params['images'] : [];

        return [
            'front' => $this->imageOrNull($images['front'] ?? null),
            'back' => $this->imageOrNull($images['back'] ?? null),
            'face' => $this->imageOrNull(
                $images['face'] ?? $params['intellicheck']['facial']['data']['photoFace'] ?? null
            ),
        ];
    }

    private function imageOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
