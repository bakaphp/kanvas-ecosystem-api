<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Support\Arr;
use Baka\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Enums\EmailTemplateEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;

/**
 * Without a recipient the digest is one company's, sent to that company's configured recipients.
 * With a recipient it is that user's combined digest over $companies: settings and the email
 * template come from the app, since one email cannot follow several companies' configurations.
 */
class GenerateDailyLeadsDigestAction
{
    private const int DEFAULT_HOURS = 24;

    /**
     * @param Collection<int, Companies> $companies
     */
    public function __construct(
        private readonly Apps $app,
        private readonly Collection $companies,
        private readonly ?int $hours = null,
        private readonly ?Users $recipient = null,
    ) {
    }

    /**
     * @param list<string> $skipRecipients
     * @return array<string, mixed>
     */
    public function execute(bool $dryRun = false, array $skipRecipients = []): array
    {
        $hours = max(1, $this->hours ?? (int) $this->setting(ConfigurationEnum::DAILY_LEADS_DIGEST_HOURS, self::DEFAULT_HOURS));
        $now = now();
        $cutoff = $now->copy()->subHours($hours);
        $leads = Lead::query()
            ->with([
                'company',
                'people.emails',
                'people.phones',
                'receiver',
                'source',
                'branch',
            ])
            ->where('apps_id', $this->app->getId())
            ->whereIn('companies_id', $this->companies->map(fn (Companies $company): int => $company->getId())->all())
            ->where('is_deleted', 0)
            ->where('created_at', '>=', $cutoff)
            ->orderByDesc('created_at')
            ->get();

        $excludedCompanies = $this->settingList(ConfigurationEnum::DAILY_LEADS_DIGEST_EXCLUDED_COMPANIES);
        $excludedEmails = array_map('strtolower', $this->settingList(ConfigurationEnum::DAILY_LEADS_DIGEST_EXCLUDED_EMAILS));
        $suspiciousEmailsCount = 0;

        $validLeads = $leads->reject(function (Lead $lead) use ($excludedCompanies, $excludedEmails, &$suspiciousEmailsCount): bool {
            if ($this->isExcludedCompany($lead, $excludedCompanies)) {
                return true;
            }

            $email = strtolower(trim((string) ($lead->people?->emails->first()?->value ?? $lead->email)));
            if ($this->isExcludedEmail($email, $excludedEmails)) {
                $suspiciousEmailsCount++;

                return true;
            }

            return false;
        })->values();

        $formattedLeads = $validLeads->map(fn (Lead $lead): array => $this->formatLead($lead));
        $total = $validLeads->count();

        $digest = [
            'app' => $this->app,
            'company' => $this->company(),
            'is_combined' => $this->isCombined(),
            'hours' => $hours,
            'period_start' => $cutoff,
            'period_end' => $now,
            'total' => $total,
            'suspicious_emails_count' => $suspiciousEmailsCount,
            'companies_count' => $this->companies->count(),
            'by_company' => $this->topCounts($formattedLeads, 'company', limit: null),
            'top_sources' => $this->topCounts($formattedLeads, 'source'),
            'top_branches' => $this->topCounts($formattedLeads, 'branch'),
            'custom_field_summaries' => $this->customFieldSummaries($formattedLeads),
            'by_day' => $formattedLeads
                ->groupBy(fn (array $lead): string => $lead['created_at']->toDateString())
                ->map(fn (Collection $items, string $day): array => ['date' => $day, 'count' => $items->count()])
                ->values()
                ->all(),
            'leads' => $formattedLeads->all(),
            'sent' => false,
        ];

        if (! $dryRun && $total > 0) {
            $digest['sent'] = $this->sendDigest($digest, $skipRecipients);
        }

        return $digest;
    }

    private function isCombined(): bool
    {
        return $this->recipient !== null;
    }

    private function company(): ?Companies
    {
        return $this->isCombined() ? null : $this->companies->first();
    }

    private function setting(ConfigurationEnum $key, mixed $default = null): mixed
    {
        return $this->company()?->get($key->value)
            ?? $this->app->get($key->value)
            ?? $default;
    }

    /** @return list<string> */
    private function settingList(ConfigurationEnum $key): array
    {
        return Arr::fromSettingList($this->setting($key));
    }

    /** @param list<string> $excludedCompanies */
    private function isExcludedCompany(Lead $lead, array $excludedCompanies): bool
    {
        if ($excludedCompanies === []) {
            return false;
        }

        $identifiers = [
            (string) $lead->companies_id,
            (string) $lead->companies_branches_id,
            (string) ($lead->branch?->name ?? ''),
            (string) ($lead->company?->name ?? ''),
        ];
        $excluded = array_map('strtolower', $excludedCompanies);

        return array_intersect(array_map('strtolower', $identifiers), $excluded) !== [];
    }

    /** @param list<string> $excludedEmails */
    private function isExcludedEmail(string $email, array $excludedEmails): bool
    {
        if ($email === '') {
            return false;
        }

        foreach ($excludedEmails as $excluded) {
            $excluded = strtolower(trim($excluded));
            if ($email === $excluded || (str_starts_with($excluded, '@') && str_ends_with($email, $excluded))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function formatLead(Lead $lead): array
    {
        $createdAt = $lead->created_at;

        return [
            'id' => $lead->getId(),
            'name' => $lead->people?->name ?? $lead->firstname . ' ' . $lead->lastname,
            'email' => $lead->people?->emails->first()?->value ?? $lead->email,
            'phone' => $lead->people?->phones->first()?->value ?? $lead->phone ?? null,
            'company' => $lead->company?->name ?? '—',
            'source' => $lead->source?->name ?? $lead->receiver?->source_name ?? '—',
            'branch' => $lead->branch?->name ?? 'Default',
            'description' => $lead->description,
            'created_at' => $createdAt,
            'custom_fields' => $lead->getAllCustomFields(),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $leads
     * @return list<array{name: string, count: int}>
     */
    private function topCounts(Collection $leads, string $field, ?int $limit = 5): array
    {
        return $leads
            ->pluck($field)
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '' && $name !== '—')
            ->countBy()
            ->sortDesc()
            ->when($limit !== null, fn (Collection $counts): Collection => $counts->take($limit))
            ->map(fn (int $count, string $name): array => ['name' => $name, 'count' => $count])
            ->values()
            ->all();
    }

    /**
     * Twig's sandbox has no merge or sort, so a "top N by custom field" block can only be counted here.
     *
     * @param Collection<int, array<string, mixed>> $leads
     * @return array<string, array{missing: int, top: list<array{name: string, count: int}>}>
     */
    private function customFieldSummaries(Collection $leads): array
    {
        $summaries = [];
        foreach ($this->settingList(ConfigurationEnum::DAILY_LEADS_DIGEST_SUMMARY_FIELDS) as $field) {
            $values = $leads->map(fn (array $lead): array => [
                'label' => $this->customFieldLabel($lead['custom_fields'][$field] ?? null),
            ]);

            $summaries[$field] = [
                'missing' => $values->where('label', '')->count(),
                'top' => $this->topCounts($values, 'label'),
            ];
        }

        return $summaries;
    }

    private function customFieldLabel(mixed $value): string
    {
        $value = Str::jsonToArray($value);

        if (is_array($value)) {
            $value = implode(' ', array_filter(
                $value,
                static fn (mixed $part): bool => is_scalar($part) && trim((string) $part) !== ''
            ));
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @return list<string> */
    private function recipients(): array
    {
        $recipients = $this->isCombined()
            ? [(string) $this->recipient->email]
            : $this->settingList(ConfigurationEnum::DAILY_LEADS_DIGEST_RECIPIENTS);

        return array_values(array_unique(array_filter(
            $recipients,
            static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        )));
    }

    /**
     * @param array<string, mixed> $digest
     * @param list<string> $skipRecipients
     */
    private function sendDigest(array $digest, array $skipRecipients): bool
    {
        if (($digest['total'] ?? 0) === 0) {
            return false;
        }

        $skip = array_map('strtolower', $skipRecipients);
        $recipients = array_values(array_filter(
            $this->recipients(),
            static fn (string $email): bool => ! in_array(strtolower($email), $skip, true)
        ));
        if ($recipients === []) {
            return false;
        }

        $notification = new Blank(
            EmailTemplateEnum::DAILY_LEADS_DIGEST->value,
            $digest,
            ['mail'],
            $this->companies->first()
        );
        $notification->setSubject(sprintf(
            'Daily Leads Digest — %s',
            $this->company()?->name ?? $this->app->name
        ));

        foreach ($recipients as $recipient) {
            Notification::route('mail', $recipient)->notify($notification);
        }

        return true;
    }
}
