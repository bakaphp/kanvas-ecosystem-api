<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Enums\EmailTemplateEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Templates\Blank;

class GenerateDailyLeadsDigestAction
{
    private const int DEFAULT_HOURS = 24;

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly ?int $hours = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(bool $dryRun = false): array
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
            ->where('companies_id', $this->company->getId())
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
            'company' => $this->company,
            'hours' => $hours,
            'period_start' => $cutoff,
            'period_end' => $now,
            'total' => $total,
            'suspicious_emails_count' => $suspiciousEmailsCount,
            'top_sources' => $this->topCounts($formattedLeads, 'source'),
            'top_branches' => $this->topCounts($formattedLeads, 'branch'),
            'by_day' => $formattedLeads
                ->groupBy(fn (array $lead): string => $lead['created_at']->toDateString())
                ->map(fn (Collection $items, string $day): array => ['date' => $day, 'count' => $items->count()])
                ->values()
                ->all(),
            'leads' => $formattedLeads->all(),
            'sent' => false,
        ];

        if (! $dryRun && $total > 0) {
            $digest['sent'] = $this->sendDigest($digest);
        }

        return $digest;
    }

    private function setting(ConfigurationEnum $key, mixed $default = null): mixed
    {
        return $this->company->get($key->value)
            ?? $this->app->get($key->value)
            ?? $default;
    }

    /** @return list<string> */
    private function settingList(ConfigurationEnum $key): array
    {
        $value = $this->setting($key);
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value
        ), static fn (string $item): bool => $item !== ''));
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
            'name' => $lead->people?->name ?? $lead->firstname . ' ' . $lead->lastname,
            'email' => $lead->people?->emails->first()?->value ?? $lead->email,
            'phone' => $lead->people?->phones->first()?->value ?? $lead->phone ?? null,
            'source' => $lead->source?->name ?? $lead->receiver?->source_name ?? '—',
            'branch' => $lead->branch?->name ?? 'Default',
            'created_at' => $createdAt,
            'custom_fields' => $lead->getAllCustomFields(),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $leads
     * @return list<array{name: string, count: int}>
     */
    private function topCounts(Collection $leads, string $field): array
    {
        return $leads
            ->pluck($field)
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '' && $name !== '—')
            ->countBy()
            ->sortDesc()
            ->take(5)
            ->map(fn (int $count, string $name): array => ['name' => $name, 'count' => $count])
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $digest */
    private function sendDigest(array $digest): bool
    {
        if (($digest['total'] ?? 0) === 0) {
            return false;
        }

        $recipients = array_values(array_filter(
            $this->settingList(ConfigurationEnum::DAILY_LEADS_DIGEST_RECIPIENTS),
            static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        ));
        if ($recipients === []) {
            return false;
        }

        $notification = new Blank(
            EmailTemplateEnum::DAILY_LEADS_DIGEST->value,
            $digest,
            ['mail'],
            $this->company
        );
        $notification->setSubject(sprintf('Daily Leads Digest — %s', $this->company->name));

        foreach (array_unique($recipients) as $recipient) {
            Notification::route('mail', $recipient)->notify($notification);
        }

        return true;
    }
}
