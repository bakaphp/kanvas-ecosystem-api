<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Facades\Notification as LaravelNotification;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Enums\CorporateApplicationStatusEnum;
use Kanvas\Connectors\Movipass\Enums\CorporateLeadFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * The applicant is only emailed when the app has a rejection template configured — without
 * it the rejection stays internal, so customer-facing wording is an explicit decision rather
 * than a side effect of deploying.
 */
class RejectCorporateLeadAction
{
    public function __construct(
        protected readonly Lead $lead,
        protected readonly string $reason,
        protected readonly ?Users $reviewedBy = null,
    ) {
    }

    public function execute(): array
    {
        $this->lead->set(CorporateLeadFieldEnum::STATUS->value, CorporateApplicationStatusEnum::REJECTED->value);
        $this->lead->set(CorporateLeadFieldEnum::STATUS_REASON->value, $this->reason);

        if ($this->reviewedBy) {
            $this->lead->set(CorporateLeadFieldEnum::REVIEWED_BY->value, (string) $this->reviewedBy->getId());
            $this->lead->set(CorporateLeadFieldEnum::REVIEWED_AT->value, now()->toIso8601String());
        }

        $emailed = $this->sendRejectionEmail();

        return [
            'lead' => $this->lead->getId(),
            'status' => CorporateApplicationStatusEnum::REJECTED->value,
            'reason' => $this->reason,
            'applicant_notified' => $emailed,
        ];
    }

    private function sendRejectionEmail(): bool
    {
        $app = $this->lead->app;
        $templateName = trim((string) ($app->get(ConfigurationEnum::CORPORATE_REJECTED_TEMPLATE->value) ?? ''));
        $email = trim((string) $this->lead->email);

        if ($templateName === '' || $email === '') {
            return false;
        }

        $notification = new Blank($templateName, [
            'app' => $app,
            'lead' => $this->lead,
            'reason' => $this->reason,
            'contactName' => $this->lead->get('contact_name') ?? $this->lead->firstname,
        ], ['mail'], $this->lead);
        $notification->setSubject('Sobre tu solicitud corporativa');

        try {
            LaravelNotification::route('mail', $email)->notify($notification);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
