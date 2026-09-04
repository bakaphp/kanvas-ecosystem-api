<?php

declare(strict_types=1);

namespace App\Console\Commands\CustomerSuccess;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Actions\CustomerSuccess\DraftCustomerUpdateAction;
use Kanvas\Intelligence\Agents\Actions\CustomerSuccess\RequestCustomerUpdateApprovalAction;
use Kanvas\Intelligence\Agents\Enums\CustomerUpdateSkipEnum;
use Kanvas\Intelligence\Agents\Enums\KanvasReleaseFeedEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\CustomerSuccess\CustomerUpdateAgentService;
use Kanvas\Intelligence\Agents\Services\CustomerSuccess\NewsletterAudienceService;
use Throwable;

/**
 * The monthly run: every account tagged `newsletter` gets a draft, posted as an approval card for the
 * people on it tagged `newsletter`.
 *
 * Nothing is mailed here. Each account produces one locked card a human still has to approve, so a bad
 * month is caught per account rather than discovered in fifty inboxes.
 *
 * Both sides of the audience come from the tag rather than a flag, which is what makes this runnable
 * on a schedule: the single-account command stays for operators who want to name an organization and a
 * recipient by hand.
 */
class DraftMonthlyCustomerUpdatesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:customer-success:draft-monthly-updates
                            {--app_id= : one app, run whether or not it opted in; omit on the cron to run every app that has}
                            {--company_id= : limit to one company; omit for every company on the app}
                            {--agent_id= : a specific Customer Update Agent; defaults to the first in each account\'s company}
                            {--ignore-watermark : draft as if no account had been written to, for re-running while tuning copy}
                            {--dry-run : draft and report, post no approval cards}';

    protected $description = 'Draft this month\'s Kanvas update for every account tagged "newsletter" and post each as an approval card.';

    public function handle(): int
    {
        $audience = new NewsletterAudienceService();

        // An explicit --app_id is an operator running it by hand and bypasses the opt-in; the cron
        // form takes only apps whose operator turned the feature on.
        $appIds = $this->option('app_id') !== null
            ? [(int) $this->option('app_id')]
            : $audience->enabledAppIds();

        if ($appIds === []) {
            $this->warn('No app has "' . KanvasReleaseFeedEnum::MONTHLY_UPDATE_ENABLED->value . '" switched on.');
            $this->line('  <fg=gray>Enable it on the app, then tag the accounts and the people who should receive it.</>');

            return self::SUCCESS;
        }

        $tally = ['drafted' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($appIds as $appId) {
            $this->runForApp($appId, $audience, $tally);
        }

        $this->newLine();
        $this->info(sprintf(
            '%d card(s) posted, %d skipped, %d failed.',
            $tally['drafted'],
            $tally['skipped'],
            $tally['failed']
        ));

        return $tally['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<string, int> $tally
     */
    private function runForApp(int $appId, NewsletterAudienceService $audience, array &$tally): void
    {
        /** @var Apps $app */
        $app = Apps::getById($appId);

        // Per iteration, not once: Bouncer scope and the container-bound app are process-global, so
        // without this the second app resolves the first one's roles. See root CLAUDE.md.
        $this->overwriteAppService($app);

        $company = $this->option('company_id') !== null
            ? Companies::getById((int) $this->option('company_id'))
            : null;

        $organizations = $audience->organizations($app, $company);

        if ($organizations->isEmpty()) {
            $this->line('<fg=gray>' . $app->name . ' — no subscribed accounts.</>');

            return;
        }

        $this->newLine();
        $this->info($app->name . ' — ' . $organizations->count() . ' subscribed account(s).');

        foreach ($organizations as $organization) {
            $this->processOne($app, $organization, $audience, $tally);
        }
    }

    /**
     * One account never stops the run. A missing agent or a provider hiccup on account 12 must not
     * cost accounts 13 through 50 their update, so each is reported and the loop continues.
     *
     * @param array<string, int> $tally
     */
    private function processOne(
        Apps $app,
        Organization $organization,
        NewsletterAudienceService $audience,
        array &$tally
    ): void {
        $label = '  ' . $organization->name . ' (' . $organization->getId() . ')';

        $recipients = $audience->recipients($organization);

        if ($recipients === []) {
            $tally['skipped']++;
            $this->line($label . ' <fg=yellow>— no newsletter-tagged contact with a deliverable email; nobody to send to.</>');

            return;
        }

        $agent = $this->resolveAgent($app, $organization);

        if ($agent === null) {
            $tally['failed']++;
            $this->line($label . ' <fg=red>— no Customer Update Agent in company ' . $organization->companies_id . '.</>');

            return;
        }

        try {
            $result = new DraftCustomerUpdateAction(
                organization: $organization,
                agent: $agent,
                ignoreWatermark: (bool) $this->option('ignore-watermark'),
            )->execute();
        } catch (Throwable $e) {
            $tally['failed']++;
            $this->line($label . ' <fg=red>— drafting failed: ' . $e->getMessage() . '</>');

            return;
        }

        if (! $result->hasDraft()) {
            $tally['skipped']++;
            $this->line($label . ' <fg=gray>— ' . $this->skipReason($result->skipped) . '</>');

            return;
        }

        if ($this->option('dry-run')) {
            $tally['drafted']++;
            $this->line($label . ' <fg=cyan>— would send to ' . implode(', ', $recipients) . '</>');
            $this->line('     <fg=gray>' . $result->draft->subject . '</>');

            return;
        }

        $note = new RequestCustomerUpdateApprovalAction($result->draft, $agent->user, $recipients)->execute();

        if ($note === null) {
            $tally['failed']++;
            $this->line($label . ' <fg=red>— could not post to the account notes.</>');

            return;
        }

        $tally['drafted']++;
        $this->line($label . ' <fg=green>— card #' . $note->getId() . '</> for ' . implode(', ', $recipients));
    }

    private function skipReason(?CustomerUpdateSkipEnum $skipped): string
    {
        return $skipped === CustomerUpdateSkipEnum::NO_RELEASES
            ? 'nothing shipped in the window.'
            : 'the agent had nothing new worth saying to this account.';
    }

    private function resolveAgent(Apps $app, Organization $organization): ?Agent
    {
        return new CustomerUpdateAgentService()->resolve($app, $organization, (int) $this->option('agent_id'));
    }
}
