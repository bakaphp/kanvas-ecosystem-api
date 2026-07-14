<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\PipelinesStages\Actions;

use Carbon\Carbon;
use Exception;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as VinSolutionCustomFieldEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsConfigurationEnum;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\FollowUp\Models\FollowUpLog;
use Kanvas\Intelligence\PipelinesStages\Contracts\FollowUpTimeGateOverridable;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Services\DailyReportService;

use function Sentry\captureException;

/**
 * Manly Honda follow-up variant. Unlike FollowUpEngagementAction it ignores
 * FollowUpDay timing/templates entirely: the message body lives directly on the
 * pipeline stage config, keyed by {leadType}_{new|used}_{replied|unreplied}.
 * If the current stage has no entry for the lead's key, the lead advances to the
 * next stage until a matching stage is found.
 *
 * @deprecated use agent follow up
 */
final class ManlyHondaFollowUpEngagementAction implements FollowUpTimeGateOverridable
{
    private const array SUPPORTED_CHANNELS = [
        LeadCommunicationChannelEnum::SMS->value,
        LeadCommunicationChannelEnum::EMAIL->value,
    ];

    private const string MINUTES_NO_RESPONSE_KEY = 'minutes_no_response';

    protected array $skippedReasons = [];
    protected bool $ignoreTimeGate = false;
    protected ?string $template = null;

    public function __construct(
        public Lead $lead,
        protected ?FollowUpLog $log = null,
    ) {
    }

    public function setTemplate(string $template)
    {
        $this->template = $template;
    }

    public function withIgnoreTimeGate(bool $ignore = true): static
    {
        $this->ignoreTimeGate = $ignore;

        return $this;
    }

    public function execute(): ?array
    {
        if ($this->lead->get(ConfigurationEnum::AGENT_HAND_OFF->value)) {
            $this->logSkip('agent_hand_off', 'Lead has been handed off to a human');

            return null;
        }

        if (! $this->lead->isActive()) {
            $this->logSkip('not_active', 'Lead is not active');

            return null;
        }

        $sessionsByChannel = $this->resolveSessionsByChannel();
        if (empty($sessionsByChannel)) {
            $this->logSkip('no_channel', 'Lead has no sms/email session matching its preferred channel');

            return null;
        }

        $key = $this->buildLeadKey();

        // Walk forward to the stage that actually sends this lead's key on one of
        // its channels; stages that don't apply (wrong key combo, or no message
        // for the lead's channel) are skipped so the matching stage's
        // minutes_no_response governs the timing.
        $stage = $this->lead->stage;
        if (! $stage) {
            $this->logSkip('no_stage_for_lead', "No pipeline stage sends key '{$key}' on the lead's channels");

            return null;
        }

        $hoursTool = new CompanyWorkHoursTool($this->lead)->execute();
        if ($hoursTool['status'] !== 'work_hours' && ! $this->ignoreTimeGate) {
            $this->logSkip('outside_work_hours', 'Outside company work hours');

            return null;
        }

        $timezone = $this->lead->company->timezone ?? 'UTC';
        $minutesNoResponse = (int) (($stage->config['notification_engagement_rules'] ?? [])[self::MINUTES_NO_RESPONSE_KEY] ?? 0);
        $lastMessageTime = $this->lastMessageTime($sessionsByChannel, $timezone);

        $timeDiff = $lastMessageTime
            ? abs($lastMessageTime->diffInMinutes(Carbon::now($timezone)))
            : PHP_INT_MAX;

        if (! $this->ignoreTimeGate && $timeDiff < $minutesNoResponse) {
            $this->logSkip(
                'not_enough_time',
                "Only {$timeDiff} minutes since last message, stage requires {$minutesNoResponse}"
            );

            return null;
        }

        $channelMessages = ($stage->config ?? [])['notification_engagement_rules']['templates'][$key] ?? [];
        $sentMessage = null;

        foreach ($sessionsByChannel as $channel => $session) {
            $messageTemplate = $this->template ?? $channelMessages[$channel] ?? null;
            if (empty($messageTemplate)) {
                continue;
            }

            try {
                $message = new CreateMessageFollowUpAction(
                    $this->lead,
                    $stage,
                    $session,
                    $messageTemplate,
                    (float) $stage->weight,
                    $channel
                )->execute();

                $this->logSuccess('message_created', 'Follow-up message created', $session, $message);
            } catch (Exception $e) {
                captureException($e);

                continue;
            }

            if ($message === null) {
                continue;
            }

            $emailTitle = $this->lead->get('title_email_follow_up') ?? $this->lead->company->name;

            new SendMessageToLeadAction($this->lead)->execute(
                $channel,
                $message,
                $this->lead->company->get(TwilioConfigurationEnum::TWILIO_PHONE_NUMBER->value),
                $emailTitle
            );

            $this->logSuccess('message_sent', 'Follow-up message sent to lead', $session, $message);

            DailyReportService::track(
                $this->lead->app,
                $this->lead->company,
                'ai_follow_up_engagement_sent'
            );

            $intentNumber = (int) ($this->lead->get('intent_number') ?? 0) + 1;
            $this->lead->set('intent_number', $intentNumber);

            $sentMessage = $message;
        }

        if ($sentMessage) {
            $this->lead->moveToNextPipelineStage();

            return [
                'follow_up_message' => $sentMessage,
                'last_message_time' => Carbon::now($timezone)->toDateTimeString(),
            ];
        }

        return null;
    }

    protected function buildLeadKey(): string
    {
        $type = strtolower($this->lead->type?->name ?? 'internet');

        $vehicle = $this->lead->get(VinSolutionCustomFieldEnum::VEHICLE_OF_INTEREST->value);
        $condition = (is_array($vehicle) && ($vehicle['isNew'] ?? false)) ? 'new' : 'used';

        $replied = $this->lead->hasBeenContacted() ? 'replied' : 'unreplied';

        return "{$type}_{$condition}_{$replied}";
    }

    /**
     * One session per sms/email channel, restricted to the lead's preferred
     * channel when one is set (preferred email => sms is ignored entirely).
     *
     * @return array<string, Session> keyed by channel slug
     */
    protected function resolveSessionsByChannel(): array
    {
        $preferredChannel = $this->lead->get(LeadsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value);

        $sessions = Session::where('entity_namespace', '=', get_class($this->lead))
            ->where('entity_id', '=', $this->lead->getId())
            ->where('is_deleted', 0)
            ->fromApp($this->lead->app)
            ->fromCompany($this->lead->company)
            ->get();

        $byChannel = [];
        foreach ($sessions as $session) {
            $channel = $session->getChannel();
            if (! $channel || ! in_array($channel, self::SUPPORTED_CHANNELS, true)) {
                continue;
            }
            if ($preferredChannel && $channel !== $preferredChannel) {
                continue;
            }
            $byChannel[$channel] ??= $session;
        }

        return $byChannel;
    }

    /**
     * Walk the pipeline forward until a stage whose config sends the lead's key
     * on at least one of the lead's channels, moving the lead into each visited
     * stage as we go. A stage that lacks the key, or only sends it on a channel
     * the lead doesn't use, is skipped.
     *
     * @param array<int, string> $channels
     */
    protected function resolveStageForLead(string $key, array $channels): ?PipelineStage
    {
        $stage = $this->lead->stage;

        while ($stage) {
            $entry = ($stage->config ?? [])[$key] ?? null;
            if (is_array($entry)) {
                foreach ($channels as $channel) {
                    if (! empty($entry[$channel])) {
                        return $stage;
                    }
                }
            }

            $next = $this->lead->getNextPipelineStage();
            if (! $next) {
                return null;
            }

            $this->lead->pipeline_stage_id = $next->id;
            $this->lead->saveOrFail();
            $this->lead->unsetRelation('stage');
            $stage = $this->lead->stage;
        }

        return null;
    }

    /**
     * Most recent message timestamp across the lead's channels — drives the
     * "minutes without response" comparison.
     *
     * @param array<string, Session> $sessionsByChannel
     */
    protected function lastMessageTime(array $sessionsByChannel, string $timezone): ?Carbon
    {
        $latest = null;
        foreach ($sessionsByChannel as $session) {
            $lastMessage = $session->channel?->getLastMessage();
            if (! $lastMessage || ! $lastMessage->created_at) {
                continue;
            }

            $time = Carbon::parse($lastMessage->created_at, $timezone);
            if (! $latest || $time->gt($latest)) {
                $latest = $time;
            }
        }

        return $latest;
    }

    protected function logSkip(string $reason, string $message, ?Session $session = null): void
    {
        $this->skippedReasons[] = [
            'reason' => $reason,
            'message' => $message,
            'session_id' => $session?->getId(),
            'channel' => $session?->getChannel(),
            'timestamp' => now()->toDateTimeString(),
        ];

        if ($this->log) {
            $metadata = $this->log->metadata ?? [];
            $metadata['skipped_reasons'] = $this->skippedReasons;
            $this->log->update([
                'metadata' => $metadata,
                'error_message' => $message,
            ]);
        }
    }

    protected function logSuccess(
        string $action,
        string $message,
        ?Session $session = null,
        ?string $messageContent = null
    ): void {
        if (! $this->log) {
            return;
        }

        $metadata = $this->log->metadata ?? [];
        $metadata['success_actions'][] = [
            'action' => $action,
            'message' => $message,
            'session_id' => $session?->getId(),
            'channel' => $session?->getChannel(),
            'message_content' => $messageContent,
            'timestamp' => now()->toDateTimeString(),
        ];
        $this->log->update([
            'metadata' => $metadata,
        ]);
    }

    public function getSkippedReasons(): array
    {
        return $this->skippedReasons;
    }
}
