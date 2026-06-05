<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

abstract class BaseAddLeadCommentFromAgentMessageActivity extends KanvasActivity
{
    public $tries = 1;

    /**
     * Get the integration enum for this connector.
     */
    abstract protected function getIntegration(): IntegrationsEnum;

    /**
     * Validate if the company is properly configured for this integration.
     *
     * @return array|null Returns error array if validation fails, null if valid
     */
    abstract protected function validateCompanyIntegration(Message $message): ?array;

    /**
     * Add the note to the external CRM system.
     *
     * @return mixed The result from the CRM system
     */
    abstract protected function addNoteToExternalSystem(
        Lead $lead,
        string $note,
        Message $message,
        Apps $app
    ): mixed;

    /**
     * Whether to append AI chat link before adding the prefix.
     * Override to true for connectors that need link before prefix (e.g., Elead).
     */
    protected function appendLinkBeforePrefix(): bool
    {
        return false;
    }

    /**
     * Build the formatted note with agent/customer prefix and channel info.
     */
    protected function buildFormattedNote(Message $message, string $note, bool $fromAgent): string
    {
        $channelSlug = $message->channels->first()?->slug;
        $channel = $channelSlug ? ChannelCategoryEnum::getLeadChannelName($channelSlug) : 'sms';
        $agentChannel = '(' . ucfirst($channel) . ') ';

        $isNote = strtolower((string)$message->messageType?->verb) === 'note';
        $fromWho = match (true) {
            $isNote => 'Agent Note',
            $fromAgent => $agentChannel . ' ' . ($message->user->firstname . ' ' . $message->user->lastname ?? 'Sally'),
            default => 'Customer',
        };

        return $fromWho . ': ' . $note;
    }

    /**
     * Append the AI chat link to the note.
     * Can be overridden for connector-specific formatting.
     */
    protected function appendAiChatLink(string $note, ?string $aiChatLink, Apps $app, bool $fromAgent): string
    {
        if ($aiChatLink === null) {
            return $note;
        }

        return $note;
    }

    /**
     * Execute the activity.
     */
    public function execute(Message $message, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        // Optional delay for testing purposes
        if (isset($params['delay_seconds'])) {
            sleep((int) $params['delay_seconds']);
        }

        $company = $message->company;

        // Validate company integration
        $validationError = $this->validateCompanyIntegration($message);
        if ($validationError !== null) {
            return $validationError;
        }

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: $this->getIntegration(),
            additionalParams: $params,
            integrationOperation: function (Message $message, Apps $app, mixed $integrationCompany, array $additionalParams): array {
                $lead = $message->entity();

                if (! $lead instanceof Lead) {
                    return $this->failWorkflow([
                        'error' => 'Message is not linked to a Lead entity',
                    ]);
                }

                if ($message->get('sent_to_crm')) {
                    return $this->failWorkflow([
                        'error' => 'Message has already been sent to CRM',
                    ]);
                }

                $note = $message->message['content'] ?? '';

                if (empty($note)) {
                    return $this->failWorkflow([
                        'error' => 'Message content is empty',
                    ]);
                }

                if ($message->isLocked() || ! $message->isPublic()) {
                    return $this->failWorkflow([
                        'error' => 'Message is locked or not public, cannot add comment',
                    ]);
                }

                $fromAgent = (bool) ($message->message['from_me'] ?? false);
                $aiChatLink = SessionChannelService::generateChannelLink($lead, $app);

                // Build the formatted note based on connector preference
                if ($this->appendLinkBeforePrefix()) {
                    // Elead style: append link first, then prefix
                    $noteWithLink = $this->appendAiChatLink($note, $aiChatLink, $app, $fromAgent);
                    $formattedNote = $this->buildFormattedNote($message, $noteWithLink, $fromAgent);
                } else {
                    // VinSolution/DealerSocket style: prefix first, then link
                    $formattedNote = $this->buildFormattedNote($message, $note, $fromAgent);
                    $formattedNote = $this->appendAiChatLink($formattedNote, $aiChatLink, $app, $fromAgent);
                }

                // Add note to the external CRM system
                $externalResult = $this->addNoteToExternalSystem($lead, $formattedNote, $message, $app);

                // Handle failure from external system
                if (is_array($externalResult) && isset($externalResult['error'])) {
                    return $externalResult;
                }

                $message->set('sent_to_crm', true);

                return [
                    'note' => $externalResult,
                    'from_agent' => $fromAgent,
                    'lead' => $lead->getId(),
                ];
            },
            company: $company,
        );
    }
}
