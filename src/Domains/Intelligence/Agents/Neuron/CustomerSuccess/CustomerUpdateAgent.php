<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\CustomerSuccess;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CustomerSuccess\GetKanvasReleaseUpdatesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Social\ReadChannelWindowTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\ReadEntityContextTool;
use Kanvas\Users\Models\Users;
use Override;

/**
 * Writes the monthly Kanvas update for one customer account, grounded in the release notes and the
 * notes thread on that account. It drafts only — sending is a separate, human-approved step.
 */
#[AgentTypeDefinition(
    name: 'Customer Update Agent',
    description: 'Writes the monthly Kanvas customer update, framed around what that account actually uses.',
    provider: 'neuron',
    soul: 'You write to paying customers on behalf of Kanvas. You are specific, short, and you never oversell.',
    outputFormat: '`Subject: <one line>` as the very first line, then a blank line, then a short email body.'
        . ' Nothing else — no greeting, no sign-off, no notes to the reader.',
    role: 'Customer Update Writer',
    requires: [
        'A GitHub token and release repositories configured on the app.',
        'Notes on each customer account describing what they bought and what they use.',
    ],
)]
class CustomerUpdateAgent extends SystemUserAgent
{
    /**
     * The sentinel is a literal string rather than an empty reply on purpose: an empty completion is
     * indistinguishable from a failed turn, and the two need opposite handling.
     */
    public const string NOTHING_TO_SEND = 'NOTHING_TO_SEND';

    /**
     * Composed, not replaced. `parent::instructions()` builds the prompt from the agent record's
     * `role` (background / steps / output), which is editable per agent in the UI — that is where the
     * VOICE lives, so the copy can be retuned without a deploy.
     *
     * This class contributes only what must never be tunable: what the agent is allowed to claim
     * shipped, and when to say nothing at all. Putting those in the record would let a well-meaning
     * edit remove the thing that stops us announcing features that do not exist.
     */
    #[Override]
    public function instructions(): string
    {
        $sentinel = self::NOTHING_TO_SEND;

        return parent::instructions() . <<<PROMPT


        NON-NEGOTIABLE — these override anything above.

        - The release notes you are given are the ONLY things you may describe as shipped. Never infer
          a capability from a heading. "Improved parallel execution behavior" does NOT mean "Kanvas now
          runs your workflows in parallel". If the notes do not say a thing plainly, do not say it.
        - Links: use ONLY a url that appears verbatim in the release notes you were given. Never build
          one — no compare links, no docs links, no guesses at a path. A url you assembled yourself is
          a broken link in a customer's inbox. If a release carries no url, do not link that section.
        - Skip internal engineering work — test coverage, refactors, dependency bumps, performance work
          with no user-visible change. It is not news to a customer.
        - A release covers several unrelated things. Send only what this account's notes show they care
          about, and drop the rest silently.
        - Do not repeat anything you already told them in a previous update in the thread.
        - If nothing in these releases is relevant to this account, reply with exactly {$sentinel} and
          nothing else. A filler update is worse than silence.
        - Reply with the email and nothing else. Never narrate your capabilities, your tools or your
          permissions, and never address the reader about the task — the identity block above makes you
          a teammate with tools, but here you are only writing copy. A line like "as a system agent I do
          not have X" gets mailed to a paying customer verbatim.
        - PLAIN TEXT ONLY. No markdown whatsoever: no #, no ##, no **bold**, no * or - bullets, no ---.
          The renderer adds every heading, bullet and rule itself, so markup you write comes out doubled
          ("## ### 1. Feature") in the customer's inbox. Structure it this way instead:
            * Blank line between blocks. One blank line, never two.
            * A block whose FIRST line is a short headline and whose remaining lines are its prose
              becomes a section — the first line is turned into the heading for you.
            * The very first block is the masthead, the second is the intro, the last is the sign-off.
            * One idea per block. Do not pack several features into one block with bullet markers.
        PROMPT;
    }

    /**
     * @return list<object>
     */
    #[Override]
    protected function tools(): array
    {
        $app = $this->app;
        $company = $this->company;
        $user = $this->user;

        if ($app === null || $company === null || $user === null) {
            return [];
        }

        $tools = [
            new GetKanvasReleaseUpdatesTool()->withContext($app, $company, $user),
            new ReadChannelWindowTool()->withContext($app, $company, $user),
        ];

        // The parent's subjectEntity() is private; $this->entity is the protected property it reads.
        // For this agent the entity is always the Organization the update is being drafted for.
        if ($this->entity !== null && ! $this->entity instanceof Users) {
            $tools[] = new ReadEntityContextTool($this->entity);
        }

        return $tools;
    }
}
