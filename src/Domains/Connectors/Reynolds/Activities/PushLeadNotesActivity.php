<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Activities;

use Baka\Support\Url;
use Exception;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Engagements\Repositories\EngagementRepository;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Reynolds\Actions\AddNoteToLeadAction;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Kanvas\Connectors\SalesAssist\Actions\AddTradeInAction;
use Kanvas\Connectors\SalesAssist\Services\MessageNoteService;
use Kanvas\Connectors\SalesAssist\Services\MessageNotificationTextService;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

/**
 * Reynolds counterpart of VinSolution's PushLeadNotesActivity.
 *
 * Note text is built the same way every SalesAssist-family connector
 * builds it: MessageNotificationTextService renders the stage's card
 * template (with sendingUser / contact / messageData / etc. bindings),
 * MessageNoteService appends file links or previews. That keeps notes
 * consistent across Elead / VinSolution / DealerSocket / Reynolds and
 * lets the dealer / admin edit the templates without touching code.
 *
 * When the engagement lookup fails (no stage template configured, or
 * plain agent notes that never went through ActionEngine), the raw
 * message content is pushed as-is so nothing gets silently dropped.
 *
 * ID verification and esign clean-up are Kanvas-local and never reach
 * R&R — same shape as VinSolution.
 */
#[WorkflowAction(
    name: 'Reynolds Push Lead Notes',
    description: 'Copies a message into the lead\'s notes in Reynolds, so the CRM record shows the '
        . 'conversation. Writes a note only — it sends nothing to the customer. Pick the version '
        . 'matching the CRM this company runs; several connectors ship a near-identical step.',
    integration: IntegrationsEnum::REYNOLDS,
)]
class PushLeadNotesActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Message $message, Apps $app, array $params): array
    {
        $company = $message->company;

        if (empty($company->get(ConfigurationEnum::REYNOLDS_ENDPOINT->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_USERNAME->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_PASSWORD->value))
        ) {
            return ['error' => 'Reynolds credentials are not configured for this company'];
        }

        if (empty($company->get(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_STORE_NUMBER->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_AREA_NUMBER->value))
        ) {
            return ['error' => 'Reynolds dealer/store/area not configured for this company'];
        }

        $lead = $message->entity();
        if (! $lead instanceof Lead) {
            throw new Exception('Lead not found');
        }

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::REYNOLDS,
            additionalParams: $params,
            integrationOperation: function (Message $message, Apps $app, mixed $integrationCompany, array $additionalParams) use ($lead): array {
                if ($message->isLocked() || ! $message->isPublic()) {
                    return $this->failWorkflow([
                        'message_id' => $message->getId(),
                        'message' => 'Message is locked or not public, skipping Reynolds note push.',
                    ]);
                }

                $branch = $this->dispatchByVerb($message, $lead);

                return [
                    'branch' => $branch,
                    'message' => 'Reynolds integration completed',
                ];
            },
            company: $company,
        );
    }

    private function dispatchByVerb(Message $message, Lead $lead): array
    {
        $messageData = $message->getMessage();
        $verb = (string) ($messageData['verb'] ?? '');
        $status = (string) ($messageData['status'] ?? '');

        // Kanvas-local ops — don't round-trip to R&R.
        if ($verb === ActionEnum::ID_VERIFICATION->value
            && $status === ActionStatusEnum::OPEN->value
            && ! empty($messageData['data']['address']['address'])) {
            $updated = $this->idVerificationUpdatePeople($lead->people, $messageData);
            $lead->set('dont-run-id-verification-manual', 1);

            return ['verb' => $verb, 'local' => true, 'idVerification' => $updated];
        }

        if ($verb === ActionEnum::ESIGN_DOCS->value
            && $status === ActionStatusEnum::SUBMITTED->value) {
            $this->cleanEsignature($lead);

            return ['verb' => $verb, 'local' => true, 'esignCleaned' => true];
        }

        // Trade-in: R&R USL has no trade sub-flow, so store the submitted form
        // on the lead (tradein_data + tradein_imported) for the SalesAssist
        // extension to import. Kanvas-local, then still push a note documenting it.
        $tradeIn = null;
        if (in_array($verb, [
            ActionEnum::TRADE_WALK->value,
            ActionEnum::PAYOFF_FORM->value,
            ActionEnum::ADD_TRADE->value,
        ], true) && $status === ActionStatusEnum::SUBMITTED->value) {
            $tradeIn = new AddTradeInAction(
                $lead,
                'Trade-In Ready to be imported into Reynolds.'
            )->execute($messageData);
        }

        // Everything else — build a note the SalesAssist way: engagement
        // template rendered via MessageNotificationTextService + file
        // links from MessageNoteService. Falls back to the raw content of
        // the message when there's no engagement template to render (plain
        // agent notes, unwired verbs).
        $note = $this->buildStructuredNote($message, $lead) ?? $this->extractNoteContent($message);

        $result = $this->pushNote($lead, $note, $verb !== '' ? $verb : 'raw');
        if ($tradeIn !== null) {
            $result['tradeIn'] = $tradeIn;
        }

        return $result;
    }

    private function buildStructuredNote(Message $message, Lead $lead): ?string
    {
        $messageData = $message->getMessage();

        try {
            $linkPreview = Url::getShortUrl($messageData['link'] ?? '', $lead->app);
        } catch (Throwable $e) {
            $linkPreview = $messageData['link'] ?? null;
        }

        try {
            if ($newLink = new MessageNoteService($message)->generateFileLinks()) {
                $linkPreview = $newLink;
            }
        } catch (Throwable $e) {
            // Files are optional context — keep whatever link preview we already have.
        }

        try {
            $parentEngagement = $message->getEngagement();
            $currentEngagement = EngagementRepository::findEngagementForLeadAndEntity(
                $lead,
                $messageData['verb'] ?? null,
                $messageData['status'] ?? null,
                $parentEngagement->entity_uuid,
            );
        } catch (Throwable $e) {
            return null;
        }

        try {
            $engagementText = new MessageNotificationTextService($currentEngagement ?: $parentEngagement)->cardText();
        } catch (Throwable $e) {
            return null;
        }

        $engagementText = trim((string) $engagementText);
        if ($engagementText === '' && empty($linkPreview)) {
            return null;
        }

        return trim($engagementText . ' ' . (string) ($linkPreview ?? ''));
    }

    private function pushNote(Lead $lead, string $note, string $verb): array
    {
        $note = trim($note);
        if ($note === '') {
            return ['verb' => $verb, 'skipped' => 'empty note'];
        }

        try {
            $result = new AddNoteToLeadAction($lead, $note)->execute();
        } catch (Throwable $e) {
            return ['verb' => $verb, 'error' => 'Reynolds USL Note failed: ' . $e->getMessage()];
        }

        return ['verb' => $verb, 'note' => $result];
    }

    public function idVerificationUpdatePeople(People $people, array $message): array
    {
        $people->addDefaultAddress(Address::from([
            'address' => $message['data']['address']['address'],
            'city' => $message['data']['address']['city'],
            'state' => $message['data']['address']['state'],
            'zip' => $message['data']['address']['zipcode'],
        ]));

        return $message['data'];
    }

    public function cleanEsignature(Lead $lead): void
    {
        $lead->set('prefill-sign-docs-' . time(), $lead->get('prefill-sign-docs'));
        $lead->set('actions_pdf_custom_form-' . time(), $lead->get('actions_pdf_custom_form'));

        $lead->del('prefill-sign-docs');
        $lead->del('actions_pdf_custom_form');
    }

    private function extractNoteContent(Message $message): string
    {
        $content = $message->message['content'] ?? '';

        return is_string($content) ? trim($content) : '';
    }
}
