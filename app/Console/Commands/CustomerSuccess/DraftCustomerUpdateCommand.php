<?php

declare(strict_types=1);

namespace App\Console\Commands\CustomerSuccess;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Support\SmtpRuntimeConfiguration;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Actions\CustomerSuccess\DraftCustomerUpdateAction;
use Kanvas\Intelligence\Agents\Actions\CustomerSuccess\RequestCustomerUpdateApprovalAction;
use Kanvas\Intelligence\Agents\DataTransferObject\CustomerUpdateDraft;
use Kanvas\Intelligence\Agents\DataTransferObject\CustomerUpdateResult;
use Kanvas\Intelligence\Agents\Enums\CustomerUpdateSkipEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\CustomerSuccess\CustomerUpdateAgentService;
use Kanvas\Intelligence\Agents\Services\CustomerSuccess\CustomerUpdateRenderer;
use Kanvas\Intelligence\Agents\Services\CustomerSuccess\NewsletterAudienceService;
use Kanvas\Notifications\KanvasMailable;
use Throwable;

/**
 * Drafts one account's Kanvas update and prints it. Sends nothing, writes nothing — this is the v0.1
 * entry point: read the draft, decide whether the copy is good enough to send by hand.
 */
class DraftCustomerUpdateCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:customer-success:draft-update
                            {--app_id= : the Kanvas app the customer belongs to}
                            {--organization_id= : the customer account to draft for}
                            {--agent_id= : a specific Customer Update Agent; defaults to the first in the company}
                            {--html : print the rendered HTML email body instead of the plain text}
                            {--test-email= : mail the draft to ONE address to see how it renders in a real inbox}
                            {--request-approval : post the draft to the account as a locked approval card}
                            {--ignore-watermark : draft as if the account had never been written to, for re-running the same month while tuning copy}
                            {--recipient= : override who the email goes to; defaults to the newsletter-tagged people on the account}';

    protected $description = 'Draft this month\'s Kanvas update for one customer account. Prints it, sends nothing.';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->option('app_id'));
        $this->overwriteAppService($app);

        // App-scoped rather than getByIdFromCompanyApp: a CLI run by the platform operator has no
        // "current company", and the account's own company is what everything downstream derives from.
        // Scoping still stops an id from another app resolving here.
        /** @var Organization $organization */
        $organization = Organization::getById((int) $this->option('organization_id'), $app);

        $agent = $this->resolveAgent($app, $organization);
        if ($agent === null) {
            $this->error(
                'No Customer Update Agent in company ' . $organization->companies_id . '. '
                . 'Hire one of type "Customer Update Agent", or pass --agent_id.'
            );

            return self::FAILURE;
        }

        if ($organization->notes === null) {
            $this->warn(
                'This account has no notes channel yet, so the draft has no context to personalise from. '
                . 'Post a note on it first — what they bought, what they use, what they care about.'
            );
        }

        // Ahead of the draft, because drafting costs an LLM turn and --request-approval is useless
        // without somebody to send to. The batch already gates this way; this brings the one-off in line.
        if ((bool) $this->option('request-approval') && $this->approvalRecipients($organization) === []) {
            $this->error(
                'Nobody to send to. Pass --recipient, or tag the people on this account with "'
                . NewsletterAudienceService::TAG . '".'
            );

            return self::FAILURE;
        }

        try {
            $result = new DraftCustomerUpdateAction(
                organization: $organization,
                agent: $agent,
                ignoreWatermark: (bool) $this->option('ignore-watermark'),
            )->execute();
        } catch (Throwable $e) {
            // The action deliberately does not swallow provider failures into friendly prose, so a
            // failed turn arrives here as an exception rather than as something sendable.
            $this->error('Drafting failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        if (! $result->hasDraft()) {
            $this->reportSkip($organization, $result);

            return self::SUCCESS;
        }

        $draft = $result->draft;

        $this->renderDraft($organization, $draft);

        match (true) {
            (bool) $this->option('request-approval') => $this->requestApproval($draft, $agent),
            (bool) $this->option('test-email') => $this->sendTestEmail(
                $draft,
                $organization,
                (string) $this->option('test-email')
            ),
            default => $this->reportNothingSent(),
        };

        return self::SUCCESS;
    }

    /**
     * Hands the draft to a human instead of sending it. Nothing is mailed here — approving the card is
     * what sends, and what advances the watermark.
     */
    private function requestApproval(CustomerUpdateDraft $draft, Agent $agent): void
    {
        $recipients = $this->approvalRecipients($draft->organization);

        $note = new RequestCustomerUpdateApprovalAction($draft, $agent->user, $recipients)->execute();

        if ($note === null) {
            $this->error('  Could not post the draft to the account notes — no approval was requested.');

            return;
        }

        $this->info('  Posted as approval card #' . $note->getId() . ' on the account notes, locked and private.');
        $this->line('  <fg=gray>Approve it in the UI to mail it to ' . implode(', ', $recipients) . ' and advance the watermark.</>');
        $this->line('');
    }

    /**
     * A real send down the app's own SMTP path, so what lands in the inbox is exactly what a customer
     * would see — `emails.layout` is a bare `{!! $html !!}` passthrough, so nothing rewrites the markup
     * on the way out.
     *
     * This is NOT the send path. A customer send has to originate from an approved message, and that
     * gate does not exist yet — so this stays a single-recipient operator tool for checking rendering,
     * and deliberately advances no state: no watermark, no note, no suppression check. When the
     * approval-triggered send is built, it does not call this; it renders and mails on approval.
     */
    private function sendTestEmail(CustomerUpdateDraft $draft, Organization $organization, string $recipient): void
    {
        $smtp = new SmtpRuntimeConfiguration($organization->app, $organization->company);
        $from = $smtp->getFromEmail();

        // Rendered exactly the way the approved send renders it, DB template included — a preview that
        // skipped the template would be checking a layout no customer will ever receive.
        $html = $this->renderHtml($draft, $organization);

        try {
            Mail::send(
                new KanvasMailable($smtp->loadSmtpSettings(), $html)
                    ->from($from['address'], $from['name'])
                    ->to($recipient)
                    ->subject($draft->subject)
            );
        } catch (Throwable $e) {
            $this->error('  Test send failed: ' . $e->getMessage());

            return;
        }

        $this->line('  <fg=yellow>Test send only — approval was bypassed and no customer was mailed.</>');
        $this->info('  Sent to ' . $recipient . ' as ' . $from['address'] . ' (' . $from['name'] . ').');
        $this->line('');
    }

    /**
     * An explicit --recipient overrides; otherwise the account's newsletter-tagged people, so the
     * one-off command and the monthly batch reach the same audience.
     *
     * @return list<string>
     */
    private function approvalRecipients(Organization $organization): array
    {
        $recipient = trim((string) $this->option('recipient'));

        return $recipient !== ''
            ? [$recipient]
            : new NewsletterAudienceService()->recipients($organization);
    }

    private function renderDraft(Organization $organization, CustomerUpdateDraft $draft): void
    {
        $rule = str_repeat('─', 74);

        $this->line('');
        $this->line('  <options=bold>' . $organization->name . '</>  ·  org ' . $organization->getId());
        $this->line('');
        $this->line('  <fg=gray>Context</>    ' . $this->contextSummary($organization));
        $this->line('  <fg=gray>Releases</>   ' . $this->releaseSummary($draft));
        $this->line('  <fg=gray>Covers to</>  ' . ($draft->coveredThrough?->toDateString() ?? 'n/a'));
        $this->line('');
        $this->line($rule);
        $this->line('<options=bold>Subject:</> ' . $draft->subject);
        $this->line('');
        $this->line($this->option('html')
            ? $this->renderHtml($draft, $organization)
            : $draft->body);
        $this->line($rule);
        $this->line('');
    }

    /**
     * The preview and the real send must render identically, so both go through here — a difference
     * between them means an operator signs off on a layout the customer never receives.
     */
    private function renderHtml(CustomerUpdateDraft $draft, Organization $organization): string
    {
        $renderer = new CustomerUpdateRenderer();

        return $renderer->toEmailHtml(
            $renderer->toMarkdown($draft),
            $organization->app,
            $organization->company
        );
    }

    private function reportNothingSent(): void
    {
        $this->line('  <fg=yellow>Nothing was sent.</>');
        $this->line('  <fg=gray>Add --request-approval --recipient=them@example.com to post it as an approval</>');
        $this->line('  <fg=gray>card, or --test-email=you@example.com to just see how it renders.</>');
        $this->line('');
    }

    /**
     * Surfaced because the notes ARE the personalisation — a thin draft almost always means a thin
     * account, and this is the number that says so before you go blaming the prompt.
     */
    private function contextSummary(Organization $organization): string
    {
        $notes = $organization->notes?->messages()->count() ?? 0;

        return match (true) {
            $notes === 0 => '<fg=yellow>no notes on this account — the draft cannot be personalised</>',
            $notes < 3 => $notes . ' note(s) <fg=yellow>— thin; more context gives a better draft</>',
            default => $notes . ' notes on the account',
        };
    }

    private function releaseSummary(CustomerUpdateDraft $draft): string
    {
        $tags = $draft->releaseTags;
        $count = count($tags);

        if ($count === 0) {
            return 'none';
        }

        $range = $count === 1
            ? $tags[0]
            : $tags[0] . ' → ' . $tags[$count - 1];

        $window = $draft->coveredFrom !== null && $draft->coveredThrough !== null
            ? '  ' . $draft->coveredFrom->format('j M') . ' – ' . $draft->coveredThrough->format('j M')
            : '';

        return $count . ' · ' . $range . $window;
    }

    /**
     * The skips need opposite responses, so they must not read the same. Nothing shipped is a waiting
     * game, everything already covered is the steady state, and the agent declining after reading N
     * releases means this account has nothing on it worth writing about.
     */
    private function reportSkip(Organization $organization, CustomerUpdateResult $result): void
    {
        if ($result->skipped === CustomerUpdateSkipEnum::NO_RELEASES) {
            $this->info('Nothing to send for ' . $organization->name . ' — Kanvas published no releases in the last 30 days.');

            return;
        }

        if ($result->skipped === CustomerUpdateSkipEnum::ALREADY_COVERED) {
            $this->info(
                'Nothing to send for ' . $organization->name . ' — all '
                . $result->releasesConsidered . ' release(s) in the window predate the last update we sent.'
            );
            $this->comment('No agent turn was spent. Use --ignore-watermark to draft anyway.');

            return;
        }

        $this->info(
            'Nothing to send for ' . $organization->name . ' — the agent read '
            . $result->releasesConsidered . ' release(s) from the last 30 days and had nothing new to say.'
        );
        $this->comment(
            'Either the notes do not say what this account uses, or everything this month was already '
            . 'covered in the last update on the thread. Check the notes first.'
        );
    }

    private function resolveAgent(Apps $app, Organization $organization): ?Agent
    {
        return new CustomerUpdateAgentService()->resolve($app, $organization, (int) $this->option('agent_id'));
    }
}
