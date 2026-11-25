<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Enums\AppUsersEmailModeEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesCompanyUserForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RunsOrderReports;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Notifications\Mailings\Actions\CreateAppUserMailingAction;
use Kanvas\Notifications\Mailings\DataTransferObject\AppUserMailing as AppUserMailingData;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingAudienceEnum;
use Kanvas\Notifications\Mailings\Models\AppUserMailing;
use Kanvas\Notifications\Mailings\Services\AppUserMailingNotificationFactory;
use Kanvas\Notifications\Mailings\Services\MailingAudienceService;
use Kanvas\Templates\Repositories\TemplatesRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Email App Users', category: 'ecosystem')]
class SendAppUsersEmailTool extends Tool implements RequiresSystemAgent
{
    use GuardsAdminForTool;
    use GuardsRepeatCalls;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesCompanyUserForTool;
    use RunsOrderReports;
    use TrackByInputs;

    protected string $name = 'send_app_users_email';

    private const int MAX_SCHEDULE_DAYS_AHEAD = 60;

    private const string SEND_AT_PATTERN = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?$/';

    protected ?string $description = 'Send a template email to the app\'s ACTIVE USERS: the Kanvas users (not CRM people or leads) '
        . 'who made at least one PAID payment in the range, the same audience as active_users. Users who are inactive, '
        . 'banned, without an email, who turned off marketing email or muted every notification are excluded and '
        . 'counted. Always run mode "preview" first: it sends nothing and returns total_active_users, the excluded '
        . 'counts by reason, eligible_total and 5 masked sample emails. Then mode "test" with test_to set to the '
        . 'requesting teammate\'s email: it mails the template to that teammate only and records nothing. Only after '
        . 'they approve, mode "send" with confirm_total equal to the preview\'s eligible_total; a missing or different '
        . 'number sends nothing, and the audience is frozen at that moment. Add send_at (ISO 8601, read in timezone, '
        . 'in the future and at most 60 days ahead) to schedule that approved send instead of delivering now: the '
        . 'mailing waits as scheduled and goes out at that time, and mode "cancel" with mailing_id stops a '
        . 'scheduled mailing before then. Modes send, test and cancel need a real person in the chat who approves '
        . 'them; on a scheduled, unattended or automated run they are refused, so do not retry, report that a person '
        . 'has to approve the send in chat. Preview and status work anywhere. Filter with order_types (names from '
        . 'list_order_types), since/until (YYYY-MM-DD) and timezone exactly as in active_users. Delivery runs in the '
        . 'background in batches; the result carries the mailing id. Mode "status" with mailing_id reports how it is '
        . 'going: status, scheduled time, confirmed/eligible/sent/failed counts, started and completed times and the failure reason. '
        . 'Admin-only, and only a known requesting administrator. This is NOT for CRM people or leads (use '
        . 'create_lead_campaign / create_people_campaign) nor for a teammate (use send_email_to_user).';

    public function __construct()
    {
        $this->initRepeatGuard();
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'mode',
                type: PropertyType::STRING,
                description: 'preview = count and sample, sends nothing; test = mail only test_to; send = mail the audience (needs confirm_total, optional send_at); status = progress of a mailing (needs mailing_id); cancel = stop a scheduled mailing (needs mailing_id).',
                required: true,
                enum: array_column(AppUsersEmailModeEnum::cases(), 'value'),
            ),
            new ToolProperty(
                name: 'template_name',
                type: PropertyType::STRING,
                description: 'Name of an existing template (see list_templates / create_template). Required for preview, test and send.',
                required: false,
            ),
            new ToolProperty(
                name: 'subject',
                type: PropertyType::STRING,
                description: 'Email subject line. Omit to use the template\'s own title.',
                required: false,
            ),
            new ToolProperty(
                name: 'order_types',
                type: PropertyType::STRING,
                description: 'Optional comma-separated order-type names to restrict the active users to (see list_order_types). Omit for all types.',
                required: false,
            ),
            new ToolProperty(
                name: 'since',
                type: PropertyType::STRING,
                description: 'Lower-bound payment date, ISO YYYY-MM-DD. Omit for all-time.',
                required: false,
            ),
            new ToolProperty(
                name: 'until',
                type: PropertyType::STRING,
                description: 'Upper-bound payment date, ISO YYYY-MM-DD. Omit for open-ended.',
                required: false,
            ),
            new ToolProperty(
                name: 'timezone',
                type: PropertyType::STRING,
                description: 'IANA timezone the days are cut in. Defaults to America/Santo_Domingo.',
                required: false,
            ),
            new ToolProperty(
                name: 'test_to',
                type: PropertyType::STRING,
                description: 'mode test only: email of a user in this company. Nobody else can be targeted.',
                required: false,
            ),
            new ToolProperty(
                name: 'confirm_total',
                type: PropertyType::INTEGER,
                description: 'mode send only: the eligible_total returned by the preview. Anything else refuses the send.',
                required: false,
            ),
            new ToolProperty(
                name: 'mailing_id',
                type: PropertyType::INTEGER,
                description: 'modes status and cancel: the mailing id returned by send.',
                required: false,
            ),
            new ToolProperty(
                name: 'send_at',
                type: PropertyType::STRING,
                description: 'mode send only: ISO 8601 date and time to deliver at, e.g. 2026-12-01T09:00:00, read in timezone unless it carries its own offset. Future and at most 60 days ahead. Omit to send now.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        string $mode,
        ?string $template_name = null,
        ?string $subject = null,
        ?string $order_types = null,
        ?string $since = null,
        ?string $until = null,
        ?string $timezone = null,
        ?string $test_to = null,
        ?int $confirm_total = null,
        ?int $mailing_id = null,
        ?string $send_at = null,
    ): array {
        return $this->oncePerTurn(
            [
                'mode' => $mode,
                'template_name' => $template_name,
                'subject' => $subject,
                'order_types' => $order_types,
                'since' => $since,
                'until' => $until,
                'timezone' => $timezone,
                'test_to' => $test_to,
                'confirm_total' => $confirm_total,
                'mailing_id' => $mailing_id,
                'send_at' => $send_at,
            ],
            fn (): array => $this->run(
                $mode,
                Str::trimToNull($template_name),
                Str::trimToNull($subject),
                [
                    'order_types' => $this->parseNames($order_types),
                    'since' => Str::trimToNull($since),
                    'until' => Str::trimToNull($until),
                    'timezone' => Str::trimToNull($timezone) ?? 'America/Santo_Domingo',
                ],
                $test_to,
                $confirm_total,
                $mailing_id,
                Str::trimToNull($send_at),
            ),
            rememberFailures: false,
        );
    }

    private function run(
        string $mode,
        ?string $templateName,
        ?string $subject,
        array $criteria,
        ?string $testTo,
        ?int $confirmTotal,
        ?int $mailingId,
        ?string $sendAt,
    ): array {
        if ($denied = $this->requireRequestingAdminOrError()) {
            return $this->denied($denied['message']);
        }

        $modeEnum = AppUsersEmailModeEnum::tryFrom(strtolower(trim($mode)));
        if ($modeEnum === null) {
            return $this->invalidArgs('mode must be one of: preview, test, send, status, cancel.');
        }

        if ($this->modeNeedsAPerson($modeEnum) && ($denied = $this->requirePersonOrError())) {
            return $denied;
        }

        if ($modeEnum === AppUsersEmailModeEnum::STATUS) {
            return $this->status($mailingId);
        }

        if ($modeEnum === AppUsersEmailModeEnum::CANCEL) {
            return $this->cancel($mailingId);
        }

        if ($templateName === null) {
            return $this->invalidArgs('template_name is required for modes preview, test and send.');
        }

        try {
            TemplatesRepository::getByName($templateName, $this->app, $this->company);
        } catch (ModelNotFoundException) {
            return $this->notFound(
                ['template_name' => $templateName],
                "No template named \"{$templateName}\" exists for this company. Call create_template to make it, "
                . 'or list_templates to see what already exists.',
            );
        }

        $scheduledAt = null;
        if ($modeEnum === AppUsersEmailModeEnum::SEND && $sendAt !== null) {
            $scheduledAt = $this->parseSendAt($sendAt, $criteria['timezone']);

            if (is_array($scheduledAt)) {
                return $scheduledAt;
            }
        }

        return match ($modeEnum) {
            AppUsersEmailModeEnum::TEST => $this->sendTest($templateName, $subject, $testTo),
            AppUsersEmailModeEnum::PREVIEW => $this->runReport(fn (): array => $this->preview($criteria)),
            AppUsersEmailModeEnum::SEND => $this->runReport(
                fn (): array => $this->send(
                    $templateName,
                    $subject,
                    $criteria,
                    $confirmTotal,
                    $scheduledAt,
                )
            ),
        };
    }

    private function preview(array $criteria): array
    {
        $service = new MailingAudienceService($this->app, $this->company);
        $audience = $service->resolve(AppUserMailingAudienceEnum::ACTIVE_USERS, $criteria);
        $eligibleTotal = count($audience['eligible']);

        return $this->ok(
            [
                'total_active_users' => $audience['total'],
                'excluded' => $audience['excluded'],
                'total_excluded' => array_sum($audience['excluded']),
                'eligible_total' => $eligibleTotal,
                'sample_emails' => $service->maskedSample($audience['eligible']),
            ],
            "Nothing was sent. Sending requires mode \"send\" with confirm_total = {$eligibleTotal}; "
            . 'run a test first and get the user\'s approval. Tell the user how many were excluded and why.',
        );
    }

    private function send(
        string $templateName,
        ?string $subject,
        array $criteria,
        ?int $confirmTotal,
        ?Carbon $scheduledAt,
    ): array {
        $audience = new MailingAudienceService($this->app, $this->company)
            ->resolve(AppUserMailingAudienceEnum::ACTIVE_USERS, $criteria);
        $eligibleTotal = count($audience['eligible']);

        if ($eligibleTotal === 0) {
            return $this->noop(
                ['eligible_total' => 0, 'excluded' => $audience['excluded']],
                'No active user is eligible, so nothing was sent.',
            );
        }

        if ($confirmTotal !== $eligibleTotal) {
            return $this->invalidArgs(
                'confirm_total is missing or does not match the current audience, so nothing was sent.',
                ['eligible_total' => $eligibleTotal, 'excluded' => $audience['excluded']],
                "Run mode \"preview\" again, tell the user the count, and only after they approve call send with confirm_total = {$eligibleTotal}.",
            );
        }

        $mailing = new CreateAppUserMailingAction(
            new AppUserMailingData(
                app: $this->app,
                company: $this->company,
                requester: $this->requestingUser,
                agentId: $this->contextAgent()?->getId(),
                templateName: $templateName,
                subject: $subject,
                audience: AppUserMailingAudienceEnum::ACTIVE_USERS,
                criteria: $criteria,
                recipientIds: $audience['eligible'],
                excludedBreakdown: $audience['excluded'],
                confirmedTotal: $confirmTotal,
                scheduledAt: $scheduledAt,
            ),
        )->execute();

        if ($scheduledAt !== null) {
            return $this->ok(
                [
                    'mailing_id' => $mailing->getId(),
                    'mailing_uuid' => $mailing->uuid,
                    'status' => $mailing->status->value,
                    'scheduled_at' => $scheduledAt->toIso8601String(),
                    'eligible_total' => $eligibleTotal,
                    'excluded' => $audience['excluded'],
                ],
                'The mailing is scheduled, not sent: the recipients are frozen now and it goes out at scheduled_at '
                . '(UTC). Tell the person the date and time and the counts, and that mode "cancel" with the '
                . 'mailing id stops it before then. Never say it was already delivered.',
            );
        }

        return $this->ok(
            [
                'mailing_id' => $mailing->getId(),
                'mailing_uuid' => $mailing->uuid,
                'status' => $mailing->status->value,
                'eligible_total' => $eligibleTotal,
                'excluded' => $audience['excluded'],
            ],
            'The mailing is queued and delivers in the background in batches. Report the mailing id and the '
            . 'eligible and excluded counts; never say it was already delivered. Use mode "status" to follow it.',
        );
    }

    private function modeNeedsAPerson(AppUsersEmailModeEnum $mode): bool
    {
        return in_array(
            $mode,
            [AppUsersEmailModeEnum::SEND, AppUsersEmailModeEnum::TEST, AppUsersEmailModeEnum::CANCEL],
            true,
        );
    }

    private function requirePersonOrError(): ?array
    {
        $agent = $this->contextAgent();

        if ($agent !== null
            && $this->requestingUser !== null
            && ! KanvasConversationStore::actsAsAi($this->requestingUser, $agent)
        ) {
            return null;
        }

        return $this->denied(
            'Only a person in the chat can approve this: sending, testing and cancelling a mass mailing are '
            . 'refused on scheduled, unattended and automated runs. Nothing was done.',
            guidance: 'Do not retry. Report that a person has to approve the send in chat; you can still run '
                . 'preview and status.',
        );
    }

    private function parseSendAt(string $sendAt, string $timezone): Carbon|array
    {
        if (preg_match(self::SEND_AT_PATTERN, $sendAt) !== 1) {
            return $this->invalidArgs('send_at must be an ISO 8601 date and time such as 2026-12-01T09:00:00.');
        }

        try {
            $scheduledAt = Carbon::parse($sendAt, $timezone)->utc();
        } catch (Throwable) {
            return $this->invalidArgs('send_at or timezone could not be read; use ISO 8601 and an IANA timezone.');
        }

        if ($scheduledAt->lte(Carbon::now())) {
            return $this->invalidArgs('send_at must be in the future, so nothing was scheduled.');
        }

        if ($scheduledAt->gt(Carbon::now()->addDays(self::MAX_SCHEDULE_DAYS_AHEAD))) {
            return $this->invalidArgs(
                'send_at can be at most ' . self::MAX_SCHEDULE_DAYS_AHEAD . ' days ahead, so nothing was scheduled.',
            );
        }

        return $scheduledAt;
    }

    private function cancel(?int $mailingId): array
    {
        $mailing = $this->findMailing($mailingId, 'cancel');

        if (is_array($mailing)) {
            return $mailing;
        }

        if (! $mailing->cancelIfScheduled()) {
            return $this->invalidArgs(
                "Mailing #{$mailing->getId()} is {$mailing->status->value}, and only a scheduled mailing can be cancelled. Nothing changed.",
                ['mailing_id' => $mailing->getId(), 'status' => $mailing->status->value],
                'Report its status as it is; a mailing already queued or sending cannot be recalled.',
            );
        }

        return $this->ok(
            ['mailing_id' => $mailing->getId(), 'status' => $mailing->status->value],
            'The scheduled mailing was cancelled and nobody will be mailed by it.',
        );
    }

    private function findMailing(?int $mailingId, string $mode): AppUserMailing|array
    {
        if ($mailingId === null) {
            return $this->invalidArgs("mode {$mode} needs mailing_id: the id returned by send.");
        }

        $mailing = AppUserMailing::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereKey($mailingId)
            ->first();

        if ($mailing === null) {
            return $this->notFound(
                ['mailing_id' => $mailingId],
                "No mailing #{$mailingId} exists for this company.",
            );
        }

        return $mailing;
    }

    private function status(?int $mailingId): array
    {
        $mailing = $this->findMailing($mailingId, 'status');

        if (is_array($mailing)) {
            return $mailing;
        }

        return $this->ok(
            [
                'mailing_id' => $mailing->getId(),
                'mailing_uuid' => $mailing->uuid,
                'status' => $mailing->status->value,
                'scheduled_at' => $mailing->scheduled_at?->toIso8601String(),
                'confirmed_total' => $mailing->confirmed_total,
                'eligible_total' => $mailing->total_eligible,
                'excluded_total' => $mailing->total_excluded,
                'sent_count' => $mailing->sent_count,
                'failed_count' => $mailing->failed_count,
                'started_at' => $mailing->started_at?->toIso8601String(),
                'completed_at' => $mailing->completed_at?->toIso8601String(),
                'failed_reason' => $mailing->failed_reason,
            ],
            'Report these numbers as they are. Status scheduled means it has not started and goes out at scheduled_at; any '
            . 'status other than scheduled, completed, failed or cancelled means delivery is still running.',
        );
    }

    private function sendTest(string $templateName, ?string $subject, ?string $testTo): array
    {
        $testTo = Str::trimToNull($testTo);
        if ($testTo === null) {
            return $this->invalidArgs('mode test needs test_to: the email of a user in this company.');
        }

        $member = $this->resolveCompanyUserByEmail($testTo);
        if ($member === null) {
            return $this->denied(
                "test_to must be the email of a user in this company; \"{$testTo}\" is not one. Nothing was sent.",
                guidance: 'Ask for a teammate\'s email, or run preview/send instead.',
            );
        }

        try {
            $factory = new AppUserMailingNotificationFactory($this->app, $this->company, $this->requestingUser);

            Notification::sendNow(
                $member,
                $factory->forRecipient(
                    $factory->make($templateName, $subject, honorMarketingPreferences: false),
                    $member,
                ),
            );
        } catch (Throwable $e) {
            report($e);

            return $this->failed(
                'The test email could not be sent: ' . $e->getMessage(),
                guidance: 'Do not send the mailing until a test send succeeds.',
            );
        }

        return $this->ok(
            ['test_sent_to' => $member->email],
            'A single test email went to that teammate and no mailing was recorded. Ask them to check it, then '
            . 'run send with confirm_total from the preview.',
        );
    }
}
