<?php

declare(strict_types=1);

namespace App\Console\Commands\Guild;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Actions\GenerateDailyLeadsDigestAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Users\Models\UserCompanyApps;
use Kanvas\Users\Models\UserConfig;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Throwable;

class GuildDailyLeadsDigestCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-guild:daily-leads-digest {--app_id=} {--company_id=} {--user_id=} {--hours=} {--dry-run}';

    protected $description = 'Generate and send configured daily Guild leads digests';

    private int $processed = 0;

    private int $failures = 0;

    public function handle(): int
    {
        $appId = $this->option('app_id');
        $companyId = $this->intOption('company_id');
        $userId = $this->intOption('user_id');
        $hours = $this->intOption('hours');
        $dryRun = (bool) $this->option('dry-run');

        $apps = Apps::query()
            ->where('is_actived', 1)
            ->where('is_deleted', 0)
            ->when($appId !== null && $appId !== '', fn ($query) => $query->where('id', (int) $appId))
            ->orderBy('id')
            ->get();

        foreach ($apps as $app) {
            $this->overwriteAppService($app);

            // --company_id and --user_id are targeted re-sends of one kind of digest; neither pulls in the other.
            $combinedRecipients = $companyId !== null
                ? collect()
                : $this->combinedRecipients($app, $userId);
            $combinedEmailsByCompany = [];
            foreach ($combinedRecipients as $recipient) {
                foreach ($recipient['companies'] as $company) {
                    $combinedEmailsByCompany[$company->getId()][] = (string) $recipient['user']->email;
                }
            }

            $companyLinks = $userId !== null
                ? collect()
                : UserCompanyApps::query()
                    ->where('apps_id', $app->getId())
                    ->where('is_deleted', 0)
                    ->when($companyId !== null, fn ($query) => $query->where('companies_id', $companyId))
                    ->with('company')
                    ->get();

            foreach ($companyLinks as $companyLink) {
                $company = $companyLink->company;
                if (! $company instanceof Companies || ! $this->isDigestEnabledFor($app, $company)) {
                    continue;
                }

                $this->runDigest(
                    new GenerateDailyLeadsDigestAction($app, collect([$company]), $hours),
                    sprintf('app=%d company=%d', $app->getId(), $company->getId()),
                    $dryRun,
                    $combinedEmailsByCompany[$company->getId()] ?? []
                );
            }

            foreach ($combinedRecipients as $recipient) {
                $this->runDigest(
                    new GenerateDailyLeadsDigestAction(
                        app: $app,
                        companies: $recipient['companies'],
                        hours: $hours,
                        recipient: $recipient['user'],
                    ),
                    sprintf(
                        'app=%d user=%d companies=%d',
                        $app->getId(),
                        $recipient['user']->getId(),
                        $recipient['companies']->count()
                    ),
                    $dryRun
                );
            }
        }

        $this->line(sprintf('Daily leads digest: %d processed, %d failed.', $this->processed, $this->failures));

        return $this->failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Users who opted into one combined digest, each with the digest-enabled companies they belong to
     * in this app. Membership is the only scope: nobody sees a company they are not a member of.
     *
     * @return Collection<int, array{user: Users, companies: Collection<int, Companies>}>
     */
    private function combinedRecipients(Apps $app, ?int $userId): Collection
    {
        $userIds = UserConfig::query()
            ->where('name', ConfigurationEnum::DAILY_LEADS_DIGEST_COMBINED->value)
            ->when($userId !== null, fn ($query) => $query->where('users_id', $userId))
            ->get()
            ->filter(fn (UserConfig $config): bool => $this->isTruthySetting($config->value))
            ->pluck('users_id')
            ->all();

        if ($userIds === []) {
            return collect();
        }

        $companyIdsByUser = UsersAssociatedApps::query()
            ->where('apps_id', $app->getId())
            ->whereIn('users_id', $userIds)
            ->where('companies_id', '>', 0)
            ->where('is_deleted', 0)
            ->get(['users_id', 'companies_id'])
            ->groupBy('users_id')
            ->map(fn (Collection $rows): array => $rows->pluck('companies_id')->unique()->values()->all());

        $companies = Companies::query()
            ->whereIn('id', $companyIdsByUser->flatten()->unique()->all())
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->get()
            ->filter(fn (Companies $company): bool => $this->isDigestEnabledFor($app, $company))
            ->keyBy('id');

        return Users::query()
            ->whereIn('id', $companyIdsByUser->keys()->all())
            ->where('is_deleted', 0)
            ->get()
            ->map(fn (Users $user): array => [
                'user' => $user,
                'companies' => collect($companyIdsByUser[$user->getId()])
                    ->map(fn (mixed $companyId): ?Companies => $companies->get((int) $companyId))
                    ->filter()
                    ->values(),
            ])
            ->filter(fn (array $recipient): bool => $recipient['companies']->isNotEmpty())
            ->values();
    }

    /**
     * @param list<string> $skipRecipients
     */
    private function runDigest(
        GenerateDailyLeadsDigestAction $digest,
        string $label,
        bool $dryRun,
        array $skipRecipients = []
    ): void {
        try {
            $result = $digest->execute($dryRun, $skipRecipients);
            $this->processed++;
            $deliveryStatus = $result['total'] === 0
                ? ' not sent (0 leads)'
                : ($dryRun
                    ? ' dry-run (delivery skipped)'
                    : ($result['sent'] ? ' sent' : ' not sent (no recipients configured)'));
            $this->info(sprintf(
                '%s %s leads=%d suspicious_emails=%d%s',
                $dryRun ? 'Would send' : 'Processed',
                $label,
                $result['total'],
                $result['suspicious_emails_count'],
                $deliveryStatus
            ));
        } catch (Throwable $exception) {
            $this->failures++;
            $this->error(sprintf('Failed %s: %s', $label, $exception->getMessage()));
            report($exception);
        }
    }

    private function isDigestEnabledFor(Apps $app, Companies $company): bool
    {
        if (! $company->is_active || $company->is_deleted) {
            return false;
        }

        return $this->isTruthySetting(
            $company->get(ConfigurationEnum::DAILY_LEADS_DIGEST_ENABLED->value)
                ?? $app->get(ConfigurationEnum::DAILY_LEADS_DIGEST_ENABLED->value)
        );
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return $value !== null && $value !== '' ? (int) $value : null;
    }

    private function isTruthySetting(mixed $value): bool
    {
        return is_scalar($value) && filter_var((string) $value, FILTER_VALIDATE_BOOLEAN);
    }
}
