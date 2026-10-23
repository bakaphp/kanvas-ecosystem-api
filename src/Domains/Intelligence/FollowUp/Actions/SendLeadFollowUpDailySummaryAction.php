<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpEmailTemplateEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

/**
 * Emails a company's `follow_up.daily.summary` to its active members holding the app's `FollowUpDev`
 * role. A day with nothing queued, sent, failed or exhausted sends nothing.
 */
final class SendLeadFollowUpDailySummaryAction
{
    public function __construct(
        protected readonly Apps $app,
        protected readonly Companies $company,
        protected readonly Event $summary,
    ) {
    }

    /**
     * @return list<string> the addresses it was sent to
     */
    public function execute(): array
    {
        $payload = (array) $this->summary->payload;

        if (! $this->hasActivity($payload)) {
            return [];
        }

        $recipients = $this->recipients();
        if ($recipients->isEmpty()) {
            return [];
        }

        $payload['failed']['recent'] = $this->withLeadTitles($payload['failed']['recent'] ?? []);
        $payload['company_name'] = $this->company->name;

        $notification = new Blank(
            FollowUpEmailTemplateEnum::DAILY_SUMMARY->value,
            $payload,
            ['mail'],
            $this->company
        );
        $notification->setSubject(sprintf(
            'Follow-up summary %s — %s',
            $payload['for_date'] ?? '',
            $this->company->name
        ));

        Notification::send($recipients, $notification);

        return $recipients->map(fn (Users $user): string => (string) $user->email)->values()->all();
    }

    /**
     * @return Collection<int, Users>
     */
    private function recipients(): Collection
    {
        try {
            return UsersRepository::getActiveCompanyAppUsersByRole(
                $this->company,
                $this->app,
                RolesEnums::FOLLOW_UP_DEV->value
            )->get();
        } catch (ModelNotFoundException) {
            Log::info('Follow-up daily summary: FollowUpDev role not created for app; run kanvas:create-role FollowUpDev {app_id}', [
                'app_id' => $this->app->getId(),
                'company_id' => $this->company->getId(),
            ]);

            return new Collection();
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hasActivity(array $payload): bool
    {
        foreach (['queued', 'sent', 'failed', 'exhausted'] as $bucket) {
            if (($payload[$bucket]['total'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $failures
     * @return list<array<string, mixed>>
     */
    private function withLeadTitles(array $failures): array
    {
        $titles = Lead::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereIn('id', array_column($failures, 'lead_id'))
            ->pluck('title', 'id');

        return array_map(
            fn (array $failure): array => [...$failure, 'lead_title' => $titles[$failure['lead_id']] ?? null],
            $failures
        );
    }
}
