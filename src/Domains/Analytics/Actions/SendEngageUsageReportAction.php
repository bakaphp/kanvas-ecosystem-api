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
 * Managers only — no fallback to Admin or Owner. A company with no manager gets no report, which is
 * logged; assign the role rather than widening the recipient list.
 */
class SendEngageUsageReportAction
{
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
        try {
            return UsersRepository::getCompanyAppUserByRole(
                $this->company,
                $this->app,
                RolesEnums::MANAGER->value,
            )
                ->notDeleted()
                ->whereNotNull('users.email')
                ->where('users.email', '!=', '')
                ->get();
        } catch (ModelNotFoundException) {
            // Managers role not bootstrapped for this app.
            return new EloquentCollection();
        }
    }

    private function rangeLabel(): string
    {
        $from = $this->request->from;
        $to = $this->request->to;

        return $from->format('M j') . ' – ' . $to->format('M j, Y');
    }
}
