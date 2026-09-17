<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification as LaravelNotification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationSettingEnum as Setting;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * A rejection always reaches the applicant with its reason (§13.3). The app can point
 * `corporate_application_rejected_template` at its own wording; otherwise the shipped
 * `corporate-rejected` template (database/data/movipass_corporate_email_templates.json) is used.
 */
class RejectCorporateApplicationAction
{
    public const string DEFAULT_TEMPLATE = 'corporate-rejected';

    public function __construct(
        protected readonly Model $application,
        protected readonly Apps $app,
        protected readonly string $reason,
        protected readonly ?Users $reviewedBy = null,
    ) {
    }

    public function execute(): array
    {
        Field::STATUS->writeTo($this->application, CorporateApplicationStatusEnum::REJECTED->value);
        Field::STATUS_REASON->writeTo($this->application, $this->reason);

        if ($this->reviewedBy) {
            Field::REVIEWED_BY->writeTo($this->application, (string) $this->reviewedBy->getId());
            Field::REVIEWED_AT->writeTo($this->application, now()->toIso8601String());
        }

        return [
            'application' => $this->application->getId(),
            'status' => CorporateApplicationStatusEnum::REJECTED->value,
            'reason' => $this->reason,
            'applicant_notified' => $this->sendRejectionEmail(),
        ];
    }

    private function sendRejectionEmail(): bool
    {
        $email = trim((string) $this->application->email);

        if ($email === '') {
            return false;
        }

        $templateName = trim((string) (Setting::REJECTED_TEMPLATE->readFrom($this->app) ?: self::DEFAULT_TEMPLATE));

        $notification = new Blank($templateName, [
            'app' => $this->app,
            'lead' => $this->application,
            'reason' => $this->reason,
            'contactName' => $this->application->get('contact_name') ?? $this->application->firstname,
        ], ['mail'], $this->application);
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
