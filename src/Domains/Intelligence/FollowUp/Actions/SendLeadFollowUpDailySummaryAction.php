<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpEmailTemplateEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Notifications\Templates\Blank;

/**
 * Emails a company's `follow_up.daily.summary` to `lead_follow_up_summary_recipients` (company
 * setting, app as fallback). A day with nothing queued, sent, failed or exhausted sends nothing.
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
        $recipients = $this->recipients();

        if ($recipients === [] || ! $this->hasActivity($payload)) {
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

        foreach ($recipients as $recipient) {
            Notification::route('mail', $recipient)->notify($notification);
        }

        return $recipients;
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        $key = ConfigurationEnum::LEAD_FOLLOW_UP_SUMMARY_RECIPIENTS->value;
        $value = $this->company->get($key) ?? $this->app->get($key);
        $list = is_array($value) ? $value : Str::commaList(is_string($value) ? $value : null);

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $email): string => trim((string) $email), $list),
            fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        )));
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
