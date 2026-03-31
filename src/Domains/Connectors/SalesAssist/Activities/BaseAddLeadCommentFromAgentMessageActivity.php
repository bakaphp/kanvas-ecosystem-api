<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Support\Url;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Notifications\Channels\OneSignalNotificationChannel;
use Kanvas\Notifications\Channels\TwilioSmsChannel;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use NotificationChannels\Expo\ExpoChannel;

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
     * Get the enum key for tracking manager notification timestamp.
     * Return null if not tracking notification timestamps.
     */
    protected function getManagerNotifiedAtKey(): ?string
    {
        return null;
    }

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

        //return ($fromAgent ? $agentChannel . 'Sally: ' : 'Customer: ') . $note;
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

        //$shortUrl = Url::getShortUrl($aiChatLink, $app) . '?openInSa=true';
        //$linkText = "\nView Full Conversation here: {$shortUrl}";

        //if (strlen($note) + strlen($linkText) > 200) {
        //    return substr($note, 0, 200 - strlen($linkText) - 5) . '...' . $linkText;
        //}

        return $note;
    }

    /**
     * Execute the activity.
     */
    public function execute(Message $message, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
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

                // Notify managers
                $sentManagerNotification = false;
                if (! $fromAgent && $lead->company->get('ai_manager_notifications')) {
                    $this->notifyManagers($message, $lead);
                    $sentManagerNotification = true;
                }

                return [
                    'note' => $externalResult,
                    'from_agent' => $fromAgent,
                    'lead' => $lead->getId(),
                    'sent_manager_notification' => $sentManagerNotification,
                ];
            },
            company: $company,
        );
    }

    /**
     * Notify managers about customer engagement.
     */
    protected function notifyManagers(Message $message, Lead $lead): void
    {
        $hoursTool = new CompanyWorkHoursTool($message)->execute();
        if ($hoursTool['status'] !== 'work_hours') {
            return;
        }

        // Check if we should only notify once
        $notifiedAtKey = $this->getManagerNotifiedAtKey();
        if ($notifiedAtKey !== null
            && $lead->company->get(IntelligenceConfigurationEnum::AI_ENGAGEMENT_MESSAGE_ONLY_ONE_NOTIFICATION->value)
            && $lead->get($notifiedAtKey)) {
            return;
        }

        $notification = new Blank(
            templateName: 'agent-manager-notification',
            data: [
                'message' => $message,
                'company' => $message->company,
                'app' => $message->app,
                'user' => $message->user,
            ],
            via: ['sms', 'push', 'expo', 'mail'],
            entity: $message
        );

        $notification->setSubject($lead->people->name . ' Engaged with Sally');
        $notification->setPushTemplateName('agent_manager_push_notification');
        $notification->setSmsTemplateName('agent_manager_sms_notification');

        $this->configureManagerNotificationChannels($notification, $lead);

        // Get managers
        $managers = UsersRepository::getCompanyAppUserByRole(
            $message->company,
            $message->app,
            'BDCManager'
        )->get();

        Notification::send($managers, $notification);

        $this->markManagerNotificationSent($lead);

        // Track notification timestamp if key is provided
        if ($notifiedAtKey !== null) {
            $lead->set($notifiedAtKey, date('Y-m-d H:i:s'));
        }
    }

    protected function configureManagerNotificationChannels(Blank $notification, Lead $lead): void
    {
        $configuredChannels = $this->resolveManagerNotificationChannels($lead);

        if ($configuredChannels !== null) {
            $notification->channels = $configuredChannels;

            return;
        }

        $onlySms = (bool) $lead->company->get('ai_manager_notification_only_sms');
        $onlyMail = (bool) $lead->company->get('ai_manager_notification_only_mail');
        $onlyPush = (bool) $lead->company->get('ai_manager_notification_only_push');

        if ($onlySms) {
            $notification->channels = [TwilioSmsChannel::class];
        } elseif ($onlyMail) {
            $notification->channels = ['mail'];
        } elseif ($onlyPush) {
            $notification->channels = [
                OneSignalNotificationChannel::class,
                ExpoChannel::class,
            ];
        }
    }

    protected function resolveManagerNotificationChannels(Lead $lead): ?array
    {
        $rules = $lead->company->get(IntelligenceConfigurationEnum::AI_ENGAGEMENT_MANAGER_NOTIFICATION_RULES->value);

        if (empty($rules)) {
            return null;
        }

        if (! is_array($rules)) {
            return null;
        }

        $mode = $rules['mode'] ?? null;
        if ($mode !== 'first_push_sms_then_email') {
            return null;
        }

        $isFirstEngagement = $this->getLeadManagerEngagementNotificationCount($lead) === 0;
        $channels = $isFirstEngagement
            ? ($rules['first_engagement_channels'] ?? ['push', 'sms'])
            : ($rules['subsequent_engagement_channels'] ?? ['email']);

        return $this->mapManagerNotificationChannels((array) $channels);
    }

    protected function mapManagerNotificationChannels(array $channels): array
    {
        $resolvedChannels = [];

        foreach ($channels as $channel) {
            switch ($channel) {
                case 'sms':
                    $resolvedChannels[] = TwilioSmsChannel::class;
                    break;
                case 'email':
                case 'mail':
                    $resolvedChannels[] = 'mail';
                    break;
                case 'push':
                    $resolvedChannels[] = OneSignalNotificationChannel::class;
                    $resolvedChannels[] = ExpoChannel::class;
                    break;
            }
        }

        return array_values(array_unique($resolvedChannels));
    }

    protected function getLeadManagerEngagementNotificationCount(Lead $lead): int
    {
        return (int) ($lead->get(IntelligenceConfigurationEnum::AI_MANAGER_CUSTOMER_ENGAGEMENT_NOTIFICATION_COUNT->value) ?? 0);
    }

    protected function markManagerNotificationSent(Lead $lead): void
    {
        if (! $lead->get(IntelligenceConfigurationEnum::AI_MANAGER_FIRST_CUSTOMER_ENGAGEMENT_NOTIFIED_AT->value)) {
            $lead->set(
                IntelligenceConfigurationEnum::AI_MANAGER_FIRST_CUSTOMER_ENGAGEMENT_NOTIFIED_AT->value,
                date('Y-m-d H:i:s')
            );
        }

        $lead->set(
            IntelligenceConfigurationEnum::AI_MANAGER_CUSTOMER_ENGAGEMENT_NOTIFICATION_COUNT->value,
            $this->getLeadManagerEngagementNotificationCount($lead) + 1
        );
    }
}

