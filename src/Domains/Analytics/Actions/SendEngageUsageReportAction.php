<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Analytics\Notifications\EngageUsageReportNotification;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Enums\MessageChannelEnum;
use Kanvas\Users\Repositories\UsersRepository;

/**
 * Recipients cascade Managers → Admin → Owner, stopping at the first role that yields anyone.
 * A single hardcoded role would mean most tenants silently receive nothing — the failure mode
 * nobody notices until someone asks where their report went.
 */
class SendEngageUsageReportAction
{
    private const array RECIPIENT_ROLES = [
        RolesEnums::MANAGER,
        RolesEnums::ADMIN,
        RolesEnums::OWNER,
    ];

    public function __construct(
        protected readonly AppInterface $app,
        protected readonly Companies $company,
        protected readonly AnalyticsRequest $request,
        protected readonly MessageChannelEnum $channel = MessageChannelEnum::ALL,
        /**
         * Send here instead of to the company's managers. For previewing a real tenant's report
         * without mailing their staff.
         *
         * @var array<int, string>
         */
        protected readonly array $overrideEmails = [],
    ) {
    }

    /**
     * @return int number of recipients the report was dispatched to
     */
    public function execute(): int
    {
        $result = new BuildEngagementLeaderboardAction(
            app: $this->app,
            company: $this->company,
            request: $this->request,
            channel: $this->channel,
        )->execute();

        // An empty week is a legitimate report for an active tenant, but mailing a table of zeros to
        // a company that has never used Engage is noise.
        if ($result['rows'] === []) {
            Log::info('Engage usage report: no activity in range; skipping send', [
                'app_id' => $this->app->getId(),
                'company_id' => $this->company->getId(),
                'from' => $this->request->from->toDateString(),
                'to' => $this->request->to->toDateString(),
            ]);

            return 0;
        }

        $range = [
            'from' => $this->request->from->toDateString(),
            'to' => $this->request->to->toDateString(),
            'label' => $this->rangeLabel(),
            'channel_label' => $this->channel->label(),
        ];

        $notification = fn (): EngageUsageReportNotification => new EngageUsageReportNotification(
            $this->company,
            $result['rows'],
            $result['team'],
            $range,
        );

        if ($this->overrideEmails !== []) {
            foreach ($this->overrideEmails as $email) {
                // notifyNow, not the queue: an override is a manual preview, and the operator needs
                // an SMTP failure to surface in the console rather than in a worker log.
                NotificationFacade::route('mail', $email)->notifyNow($notification());
            }

            return count($this->overrideEmails);
        }

        $recipients = $this->resolveRecipients();

        if ($recipients->isEmpty()) {
            Log::info('Engage usage report: no recipients for company; skipping send', [
                'app_id' => $this->app->getId(),
                'company_id' => $this->company->getId(),
            ]);

            return 0;
        }

        $sent = 0;
        foreach ($recipients as $recipient) {
            NotificationFacade::send([$recipient], $notification());
            $sent++;
        }

        return $sent;
    }

    /**
     * @return EloquentCollection<int, mixed>
     */
    private function resolveRecipients(): EloquentCollection
    {
        foreach (self::RECIPIENT_ROLES as $role) {
            try {
                $recipients = UsersRepository::getCompanyAppUserByRole(
                    $this->company,
                    $this->app,
                    $role->value,
                )
                    ->notDeleted()
                    ->whereNotNull('users.email')
                    ->where('users.email', '!=', '')
                    ->get();
            } catch (ModelNotFoundException) {
                // Role not bootstrapped for this app — try the next one down.
                continue;
            }

            if ($recipients->isNotEmpty()) {
                return $recipients;
            }
        }

        return new EloquentCollection();
    }

    private function rangeLabel(): string
    {
        $from = $this->request->from;
        $to = $this->request->to;

        return $from->format('M j') . ' – ' . $to->format('M j, Y');
    }
}
