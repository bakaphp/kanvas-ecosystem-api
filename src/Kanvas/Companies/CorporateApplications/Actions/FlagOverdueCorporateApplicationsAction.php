<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Actions;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as LaravelNotification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationSettingEnum as Setting;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Notifications\Templates\Blank;
use Throwable;

/**
 * §12.4: an application nobody decided within the SLA (24h by default, `corporate_application_sla_hours`
 * per app) is stamped overdue once and escalated by email to the team behind its receiver. The
 * stamp is the idempotency key — the hourly command can run as often as it likes.
 */
class FlagOverdueCorporateApplicationsAction
{
    public const int DEFAULT_SLA_HOURS = 24;
    public const string DEFAULT_TEMPLATE = 'corporate-overdue';

    public function __construct(
        protected readonly Apps $app,
        protected readonly ?Carbon $now = null,
    ) {
    }

    /**
     * @return list<int> ids of the applications flagged on this run
     */
    public function execute(): array
    {
        $now = $this->now ?? Carbon::now();
        $slaHours = (int) (Setting::SLA_HOURS->readFrom($this->app) ?: self::DEFAULT_SLA_HOURS);
        $flagged = [];

        foreach ($this->overdueApplications($now->copy()->subHours($slaHours)) as $application) {
            Field::OVERDUE_AT->writeTo($application, $now->toIso8601String());
            $this->escalate($application, $slaHours);
            $flagged[] = $application->getId();
        }

        return $flagged;
    }

    /**
     * Two queries on purpose: custom fields live on `ecosystem`, leads on `crm`, and a cross-
     * database subquery runs on the lead connection where the other's uncommitted writes are
     * invisible (every transactional test would miss its own rows). The open-application set is
     * small, so the ids travel through PHP.
     *
     * @return iterable<Lead>
     */
    private function overdueApplications(Carbon $filedBefore): iterable
    {
        $open = self::applicationIdsWithStatus([
            CorporateApplicationStatusEnum::PENDING->value,
            CorporateApplicationStatusEnum::NEEDS_REVIEW->value,
        ]);

        if ($open === []) {
            return [];
        }

        $flagged = self::applicationIdsWithField(Field::OVERDUE_AT);

        return Lead::query()
            ->fromApp($this->app)
            ->notDeleted()
            ->where('created_at', '<=', $filedBefore)
            ->whereIn('id', array_values(array_diff($open, $flagged)))
            ->orderBy('id')
            ->cursor();
    }

    /**
     * @param list<string> $statuses
     * @return list<int>
     */
    public static function applicationIdsWithStatus(array $statuses): array
    {
        return self::customFieldQuery(Field::STATUS)
            ->whereIn('value', $statuses)
            ->pluck('entity_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @return list<int> */
    private static function applicationIdsWithField(Field $field): array
    {
        return self::customFieldQuery($field)
            ->pluck('entity_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private static function customFieldQuery(Field $field): Builder
    {
        return AppsCustomFields::query()
            ->where('model_name', Lead::class)
            ->where('name', $field->value)
            ->where('is_deleted', 0);
    }

    private function escalate(Lead $application, int $slaHours): void
    {
        $recipients = $this->escalationRecipients($application->receiver);

        if ($recipients === []) {
            return;
        }

        $templateName = (string) (Setting::OVERDUE_TEMPLATE->readFrom($this->app) ?: self::DEFAULT_TEMPLATE);

        $notification = new Blank($templateName, [
            'app' => $this->app,
            'lead' => $application,
            'applicationTitle' => $application->title,
            'applicantName' => $application->get('contact_name') ?? trim($application->firstname . ' ' . $application->lastname),
            'hoursOpen' => (int) $application->created_at->diffInHours($this->now ?? Carbon::now()),
            'slaHours' => $slaHours,
            'status' => Field::STATUS->readFrom($application),
        ], ['mail'], $application);
        $notification->setSubject('Solicitud atrasada: ' . $application->title);

        try {
            LaravelNotification::route('mail', $recipients)->notify($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The receiver's own notification list, else its rotation's, else whoever owns the receiver —
     * the same people who get the lead itself.
     *
     * @return list<string>
     */
    private function escalationRecipients(?LeadReceiver $receiver): array
    {
        if ($receiver === null) {
            return [];
        }

        $list = Str::trimToNull($receiver->notification_email)
            ?? Str::trimToNull($receiver->rotation?->leads_rotations_email)
            ?? Str::trimToNull($receiver->user?->email);

        return array_values(array_filter(array_map(
            static fn (string $email): ?string => Str::trimToNull($email),
            explode(',', (string) $list),
        )));
    }
}
