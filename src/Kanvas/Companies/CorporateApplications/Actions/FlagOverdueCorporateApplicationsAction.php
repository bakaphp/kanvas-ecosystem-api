<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Actions;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Concerns\SendsApplicationEmail;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationSettingEnum as Setting;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;

class FlagOverdueCorporateApplicationsAction
{
    use SendsApplicationEmail;

    public const int DEFAULT_SLA_HOURS = 24;
    public const string DEFAULT_TEMPLATE = 'corporate-overdue';

    public function __construct(
        protected readonly Apps $app,
        protected readonly ?Carbon $now = null,
    ) {
    }

    public function execute(): array
    {
        $now = $this->now ?? Carbon::now();
        $slaHours = max(1, (int) Setting::SLA_HOURS->readFrom($this->app, self::DEFAULT_SLA_HOURS));
        $flagged = [];

        foreach ($this->overdueApplications($now->copy()->subHours($slaHours)) as $application) {
            Field::OVERDUE_AT->writeTo($application, $now->toIso8601String());
            $this->escalate($application, $slaHours, $now);
            $flagged[] = $application->getId();
        }

        return $flagged;
    }

    private function overdueApplications(Carbon $filedBefore): iterable
    {
        $open = self::openApplicationIds();

        if ($open === []) {
            return [];
        }

        $flagged = self::applicationIds(Field::OVERDUE_AT);

        return Lead::query()
            ->fromApp($this->app)
            ->notDeleted()
            ->where('created_at', '<=', $filedBefore)
            ->whereIn('id', array_values(array_diff($open, $flagged)))
            ->orderBy('id')
            ->cursor();
    }

    public static function openApplicationIds(): array
    {
        return self::applicationIds(Field::STATUS, CorporateApplicationStatusEnum::openValues());
    }

    private static function applicationIds(Field $field, ?array $values = null): array
    {
        return AppsCustomFields::query()
            ->where('model_name', Lead::class)
            ->where('name', $field->value)
            ->where('is_deleted', 0)
            ->when($values !== null, fn (Builder $query) => $query->whereIn('value', $values))
            ->pluck('entity_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function escalate(Lead $application, int $slaHours, Carbon $now): void
    {
        $this->sendApplicationEmail(
            $this->app,
            (string) Setting::OVERDUE_TEMPLATE->readFrom($this->app, self::DEFAULT_TEMPLATE),
            'Solicitud atrasada: ' . $application->title,
            [
                'lead' => $application,
                'applicationTitle' => $application->title,
                'applicantName' => Field::contactName($application),
                'hoursOpen' => (int) $application->created_at->diffInHours($now),
                'slaHours' => $slaHours,
                'status' => Field::STATUS->readFrom($application),
            ],
            $this->escalationRecipients($application->receiver),
            $application,
        );
    }

    private function escalationRecipients(?LeadReceiver $receiver): array
    {
        $list = Str::trimToNull($receiver?->notification_email)
            ?? Str::trimToNull($receiver?->rotation?->leads_rotations_email)
            ?? Str::trimToNull($receiver?->user?->email);

        return $list === null ? [] : explode(',', $list);
    }
}
