<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intellicheck\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Connectors\Intellicheck\Actions\VerifyPeopleIdAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Re-scores an ID-verification scan from the message that already holds it. Takes no `intellicheck`
 * param because the payload is on the message, at `data.form.private_data.result`.
 *
 * ⚠️ In theory this should not need to exist, and it is not the co-buyer fix. A co-buyer is already
 * handled by `IdVerificationReportActivity` / `GenerateIdVerificationActivity` **provided the caller
 * sends `participant.peopleId` / `people_uuid`** — `findEngagementForLeadPeople()` is person-scoped,
 * so the report lands on the right engagement with none of this. Once every producer is trusted to
 * send that param, the only thing left here is the payload.
 *
 * And that is what it genuinely covers: both of those take the payload from the fired verb's params,
 * and a scan that already happened has none. Re-running or backfilling has no lead-side entry point.
 */
#[WorkflowAction(
    name: 'Generate ID Verification From Message',
    description: 'Re-scores the Intellicheck payload already stored on an ID-verification message and '
        . 'files the report PDF back onto it. For re-running or backfilling a scan that already '
        . 'happened — a live scan goes through Generate ID Verification instead.',
    integration: IntegrationsEnum::INTELLICHECK,
    params: [
        'send_email' => 'Re-send the manager report email. Defaults to false: this verb re-scores a '
            . 'scan that already notified once, and the 3-minute dedup window will have long expired.',
    ],
)]
class GenerateIdVerificationFromMessageActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Message $message, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::INTELLICHECK,
            additionalParams: $params,
            integrationOperation: function (Message $message) use ($params): array {
                $verificationData = $this->payloadFromMessage($message);

                if ($verificationData === []) {
                    return $this->failWorkflow([
                        'message' => 'Message ' . $message->getId() . ' carries no Intellicheck scan payload',
                        'entity_id' => $message->getId(),
                    ]);
                }

                $engagement = $message->engagement;

                if ($engagement === null) {
                    return $this->failWorkflow([
                        'message' => 'Message ' . $message->getId() . ' has no engagement to file the report on',
                        'entity_id' => $message->getId(),
                    ]);
                }

                $lead = $engagement->lead;
                $people = $engagement->people;

                if ($lead === null || $people === null) {
                    return $this->failWorkflow([
                        'message' => 'Engagement ' . $engagement->getId() . ' has no lead or person to verify',
                        'entity_id' => $message->getId(),
                    ]);
                }

                // No `images`: they are already on this very message, and `processDriverLicenseImages`
                // skips a field_name it finds there — nothing to re-link or re-upload.
                return new VerifyPeopleIdAction($people, $lead)->execute(
                    verificationData: $verificationData,
                    reuseExistingEngagement: true,
                    engagement: $engagement,
                    sendEmail: (bool) ($params['send_email'] ?? false),
                );
            },
            company: $message->company,
        );
    }

    /**
     * `data.form.private_data.result` is the shape the bot's postback lands in. The unwrapped
     * `private_data.result` is accepted too, for a message written straight from the receiver envelope.
     */
    private function payloadFromMessage(Message $message): array
    {
        $payload = (array) ($message->message ?? []);

        $result = $payload['data']['form']['private_data']['result']
            ?? $payload['private_data']['result']
            ?? null;

        if (! is_array($result) || $result === []) {
            return [];
        }

        // A selfie must never reach the PDF template — megabytes of base64 wkhtmltopdf would inline.
        // `is_array` is not defensive padding: the payload is external JSON, and unsetting through a
        // scalar `facial` is a fatal `Error`, not a no-op.
        if (is_array($result['facial']['data'] ?? null)) {
            unset($result['facial']['data']['photoFace']);
        }

        return $result;
    }
}
