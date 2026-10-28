<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Services;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Companies\Onboarding\Enums\ResearchStatusEnum;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Support\WebsiteUrl;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Knowledge\Research\Jobs\ResearchCompanyWebsiteJob;
use Kanvas\Users\Models\Users;

/**
 * Decides when the background check runs. The research itself lives in Knowledge\Research; this is
 * the onboarding-side gate (new website, 24 h re-run guard, one run at a time).
 */
final class OnboardingResearchDispatcher
{
    public const int RERUN_GUARD_HOURS = 24;
    public const int FORCE_COOLDOWN_MINUTES = 10;

    public function afterProfileUpdate(CompanyOnboarding $row, Users $user, ?string $previousWebsite): void
    {
        $company = $row->company;
        $website = Str::trimToNull((string) $company->website);
        if ($website === null) {
            return;
        }

        $research = (array) $row->dataGet('research', []);
        $lastWebsite = $research['website'] ?? null;
        if ($website === $previousWebsite && $website === $lastWebsite) {
            return;
        }
        if ($website === $lastWebsite && $this->isRunning($research)) {
            return;
        }

        $this->queue($row, $user, $website);
    }

    /**
     * The explicit mutation: stores the website on the company, refuses a re-run within the guard
     * window unless forced, and refuses to stack a second run on one still in flight.
     */
    public function force(CompanyOnboarding $row, Users $user, string $website, bool $force): void
    {
        $website = WebsiteUrl::normalize($website);
        $company = $row->company;
        $research = (array) $row->dataGet('research', []);

        if (! $force && $this->isRunning($research)) {
            throw new ValidationException('Company research is already running');
        }

        // force skips the guards above, so it gets its own: each run crawls and bills a model call.
        $queuedAt = Str::trimToNull((string) ($research['queued_at'] ?? ''));
        if ($force && $queuedAt !== null && Carbon::parse($queuedAt)->greaterThan(Carbon::now()->subMinutes(self::FORCE_COOLDOWN_MINUTES))) {
            throw new ValidationException(sprintf(
                'Company research was queued less than %d minutes ago; try again in a few minutes',
                self::FORCE_COOLDOWN_MINUTES,
            ));
        }

        $finishedAt = Str::trimToNull((string) ($research['finished_at'] ?? ''));
        if (
            ! $force
            && ($research['status'] ?? null) === ResearchStatusEnum::INDEXED->value
            && $finishedAt !== null
            && Carbon::parse($finishedAt)->greaterThan(Carbon::now()->subHours(self::RERUN_GUARD_HOURS))
        ) {
            throw new ValidationException(sprintf(
                'Company research ran less than %d hours ago; pass force: true to run it again',
                self::RERUN_GUARD_HOURS,
            ));
        }

        if ($company->website !== $website) {
            $company->website = $website;
            $company->saveOrFail();
        }

        $this->queue($row, $user, $website);
    }

    /**
     * @param array<string, mixed> $research
     */
    private function isRunning(array $research): bool
    {
        return ResearchStatusEnum::tryFrom((string) ($research['status'] ?? ''))?->isRunning() ?? false;
    }

    /**
     * The previous run's page list stays on the row: the next run prunes the pages that are gone.
     */
    private function queue(CompanyOnboarding $row, Users $user, string $website): void
    {
        $company = $row->company;
        $previous = (array) $row->dataGet('research', []);
        $row->dataSet('research', [
            'status' => ResearchStatusEnum::QUEUED->value,
            'website' => $website,
            'pages' => $previous['pages'] ?? [],
            'knowledge_count' => $previous['knowledge_count'] ?? 0,
            'pages_found' => 0,
            'pages_indexed' => 0,
            'queued_at' => Carbon::now()->toIso8601String(),
            'started_at' => null,
            'finished_at' => null,
            'error' => null,
            'note' => null,
        ]);

        ResearchCompanyWebsiteJob::dispatch(
            $row->app,
            $company,
            $user,
            $website,
        );
    }
}
