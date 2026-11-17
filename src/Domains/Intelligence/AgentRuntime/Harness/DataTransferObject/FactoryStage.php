<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject;

use Baka\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\FactoryProjectSettingEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Support\TaskDirectives;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Project\Models\Project;

/**
 * A stage of the mobile factory as the plan and the ordered tasks that run it on the Mac.
 *
 * The task text is written here, not by the orchestrator: a model asked to type `Repo:` / `Branch:`
 * lines and script names leaves them out, and a task with no `Repo:` runs in the agent's default
 * checkout.
 */
final readonly class FactoryStage
{
    /**
     * Every finished ticket lands here, so no stage stops for a person to merge; the person reviews the
     * whole stage once, in the release pull request into `develop`.
     */
    public const string INTEGRATION_BRANCH = 'factory/integration';

    /**
     * The app's own `CLAUDE.md` makes this mandatory after every change, and a builder follows the specs
     * its task names: left out of the task, it was skipped.
     */
    private const string APP_QA = 'Before the work leaves this branch, get the app running your change from Metro on BOTH the '
        . 'iOS simulator and the Android emulator, with the app\'s own run scripts (`yarn ios` / `yarn android`). A '
        . 'Metro, simulator, emulator or adb problem is yours to fix, not to report: repair devices with the '
        . 'factory\'s `.kanvas/device`, and remember Android reaches Metro only through `adb reverse` on the port your '
        . 'Metro uses. Then run the post-change QA the app\'s `CLAUDE.md` and `specs/` require, scoped to what you '
        . 'changed, on both platforms, asserting real outcomes and not only that a screen rendered (in apps from the '
        . 'mobile template that is the Argent sweep, `specs/qa/app-test.runbook.md`). Save the report where the specs '
        . 'say (`specs/qa/reports/` in the template) and name it in your reply. If the QA tool is not available, '
        . 'run the same checks by hand on both platforms and say so. If either platform still cannot run your change '
        . 'after you tried to fix it, reply BLOCKED with what you tried and what stops it: work is never finished with '
        . 'a platform untested, and a check that did not run is never marked passed.';

    /**
     * @param list<FactoryStep> $steps run in this order
     */
    public function __construct(
        public Project $project,
        public string $title,
        public string $description,
        public array $steps,
    ) {
    }

    /**
     * Create the app from the template, then give it its identity. Safe to start again after a stopped
     * run: step 1 skips a repository that already exists.
     */
    public static function kickoff(
        Project $project,
        string $repository,
        string $displayName,
        string $bundleId,
        ?string $notes = null
    ): self {
        $repository = self::folder($repository);
        $displayName = self::required($displayName, 'display name');
        $bundleId = self::bundleId($bundleId);
        $builder = self::builder($project);
        $template = FactoryProjectSettingEnum::templateFor($project);
        $notes = Str::trimToNull($notes);

        $create = implode("\n", [
            'Repo: ' . $template,
            'New app: ' . $repository,
            '',
            'Kickoff 1: create the app ' . $repository . ' from the template.',
            '',
            'Steps:',
            '1. Run `.kanvas/new-app check`.',
            '2. If the repository ' . $repository . ' and its clone on this Mac already exist, an earlier run created them: '
                . 'do not create them again. Otherwise run `.kanvas/new-app create ' . $repository . '`.',
            '3. If `' . $repository . '` is not in `apps.txt` yet, add it and open a draft pull request for that line into '
                . 'the template\'s `develop`.',
            '4. Reply with what the tool printed and the pull request link.',
        ]);

        $configure = implode("\n", array_filter([
            'Repo: ' . $repository,
            'Branch: kickoff/setup',
            '',
            'Kickoff 2: configure ' . $displayName . '.',
            '- Display name: ' . $displayName,
            '- Bundle id: ' . $bundleId,
            $notes !== null ? "- From the approved plan:\n" . TaskDirectives::neutralize($notes) : null,
            '',
            'Steps:',
            '1. Read `CLAUDE.md` and all of `specs/`. ' . self::startFromIntegration(),
            '2. Run `.kanvas/new-app identity --name "' . $displayName . '" --bundle ' . $bundleId . '`.',
            '3. Follow `specs/workflows/new-project-kickoff.spec.md` from "Re-theme" on.',
            '4. ' . self::APP_QA,
            '5. ' . self::landOnIntegration(),
        ], fn (?string $line): bool => $line !== null));

        return new self(
            $project,
            'Kickoff: ' . $displayName . ' (' . $repository . ')',
            'Create ' . $repository . ' from ' . $template . ' and give it its identity (' . $bundleId . ').',
            [
                new FactoryStep('Kickoff 1: create ' . $repository, $create, $builder),
                new FactoryStep('Kickoff 2: configure ' . $displayName, $configure, $builder),
            ],
        );
    }

    /**
     * One ticket on one shared branch: the designer commits the design, the builder adds the logic on top
     * and ships the pull request. A ticket with nothing on screen skips the design step, which would
     * otherwise spend its run on simulator screenshots of nothing.
     */
    public static function ticket(
        Project $project,
        string $repository,
        int $number,
        string $shortName,
        string $ticket,
        bool $needsDesign = true
    ): self {
        $repository = self::folder($repository);
        $shortName = self::required($shortName, 'ticket short name');
        $ticket = TaskDirectives::neutralize(self::required($ticket, 'ticket text'));

        if ($number <= 0) {
            throw new ValidationException('The ticket number must be the plan\'s ticket number, 1 or higher.');
        }

        $builder = self::builder($project);
        $designer = FactoryProjectSettingEnum::designerFor($project) ?? $builder;
        $branch = 'ticket/' . $number . '-' . Str::limit(Str::slug($shortName), 40, '');
        $lines = 'Repo: ' . $repository . "\nBranch: " . $branch;
        $quoted = "\n\nTicket " . $number . ":\n" . $ticket;

        $design = $lines . "\n\n"
            . self::startFromIntegration() . ' Then design ticket ' . $number . ' in code: screens, components, theme tokens, the loading/empty/error '
            . 'states, en/es copy and accessibility. Check it on iOS and Android screenshots. Commit only: do not '
            . 'push and do not open a pull request.' . $quoted;

        $build = self::startFromIntegration() . ' '
            . ($needsDesign
                ? 'Build the logic of ticket ' . $number . ' on top of the design already committed on this branch: '
                    . 'interfaces, services, hooks, data wiring, and tests for hooks and utils. Do not redo the design. Then '
                : 'Build ticket ' . $number . ': interfaces, services, hooks, data wiring, and tests for hooks and utils. '
                    . 'It has nothing on screen. Then ');

        $ship = $lines . "\n\n" . $build
            . self::APP_QA . ' Save ' . ($needsDesign ? 'iOS and Android screenshots' : 'the test output')
            . ' with the QA report: the release pull request shows them. ' . self::landOnIntegration()
            . ' The data comes from Kanvas, never '
            . 'hard-coded or dummy data: a new kind of thing the app keeps is a Kanvas message with its own '
            . 'message type (createMessage, the messages query, attachFileToMessage, tags), a single value is a '
            . 'custom field. Only if none of that can serve the ticket, say exactly what is missing in your reply '
            . 'and stop: never change kanvas-ecosystem-api yourself.' . $quoted;

        $shipStep = new FactoryStep('Ticket ' . $number . ' logic and landing: ' . $shortName, $ship, $builder);

        return new self(
            $project,
            'Ticket ' . $number . ': ' . $shortName . ' (' . $repository . ')',
            ($needsDesign ? 'Design, then logic' : 'Logic') . ' on ' . $branch . ', landed on `' . self::INTEGRATION_BRANCH . '`.',
            $needsDesign
                ? [new FactoryStep('Ticket ' . $number . ' design: ' . $shortName, $design, $designer), $shipStep]
                : [$shipStep],
        );
    }

    /**
     * The stage's one pull request: everything that landed on the integration branch, with the dated
     * changelog, into `develop`. Cut from `develop` so the pull request carries only what is new.
     */
    public static function release(Project $project, string $repository, ?string $notes = null): self
    {
        $repository = self::folder($repository);
        $builder = self::builder($project);
        $date = now()->toDateString();
        $notes = Str::trimToNull($notes);

        $text = implode("\n", array_filter([
            'Repo: ' . $repository,
            'Branch: release/' . $date,
            '',
            'Release: open the one pull request of this stage, everything on `' . self::INTEGRATION_BRANCH . '` into `develop`.',
            $notes !== null ? "From the orchestrator:\n" . TaskDirectives::neutralize($notes) : null,
            '',
            'Steps:',
            '1. Read `CLAUDE.md` and the specs on commits, pull requests and the changelog. Run `git fetch origin` and merge '
                . '`origin/' . self::INTEGRATION_BRANCH . '` into this branch, which starts from `develop`. If that adds '
                . 'nothing, reply that there is nothing to release and stop.',
            '2. Add a changelog entry dated ' . $date . ' where the specs say (otherwise `CHANGELOG.md`, newest first): '
                . 'one line per ticket that landed, read from the merge history.',
            '3. ' . self::APP_QA . ' Here "your change" is every ticket in this release.',
            '4. Push the branch and open the pull request into `develop` following the app\'s specs: Conventional Commits '
                . 'from `CLAUDE.md`, `specs/workflows/pull-request.spec.md` and the PR Description Format of '
                . '`specs/workflows/multi-app-pipeline.spec.md`. List every ticket with its QA report and screenshots. '
                . 'Wait for CI, fix what you broke, and mark it ready only when every check is green. A person merges it.',
            '5. Reply with the pull request link, its CI state and whether it is draft or ready.',
        ], fn (?string $line): bool => $line !== null));

        return new self(
            $project,
            'Release: ' . $repository . ' into develop (' . $date . ')',
            'One pull request from `' . self::INTEGRATION_BRANCH . '` into `develop`, with the dated changelog.',
            [new FactoryStep('Release ' . $date . ': pull request into develop', $text, $builder)],
        );
    }

    private static function startFromIntegration(): string
    {
        return 'Start from the integration branch: run `git fetch origin`; if `origin/' . self::INTEGRATION_BRANCH
            . '` exists, merge it into this branch, otherwise create it from `origin/develop` with `git push origin '
            . 'origin/develop:refs/heads/' . self::INTEGRATION_BRANCH . '`.';
    }

    /** A fast-forward push of the ticket branch: no checkout, no pull request, no person in the loop. */
    private static function landOnIntegration(): string
    {
        return 'Land the work on the integration branch, not in a pull request: commit following the app\'s '
            . 'Conventional Commits, run `git fetch origin` and merge `origin/' . self::INTEGRATION_BRANCH . '` into '
            . 'this branch again (another ticket may have landed), fix any conflict, re-run the app\'s type check, lint '
            . 'and unit tests, then push this branch and move the integration branch to it with `git push origin '
            . 'HEAD:' . self::INTEGRATION_BRANCH . '`. If that push is rejected, fetch, merge and push again. Never push '
            . 'to `develop` and never open a pull request: the stage ends with one release pull request into '
            . '`develop`. Reply with the commit now at the tip of `' . self::INTEGRATION_BRANCH . '`, the checks you '
            . 'ran and the QA report.';
    }

    private static function builder(Project $project): Agent
    {
        return FactoryProjectSettingEnum::builderFor($project) ?? throw new ValidationException(
            'This project has no factory builder: set its `' . FactoryProjectSettingEnum::BUILDER_AGENT->value
            . '` to an agent that works on the factory Mac.'
        );
    }

    /** A checkout folder on the Mac, which is also the GitHub repository name. */
    private static function folder(string $repository): string
    {
        $repository = (string) Str::trimToNull($repository);

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $repository) !== 1) {
            throw new ValidationException('"' . $repository . '" is not a repository name: use letters, digits, ".", "_" and "-".');
        }

        return $repository;
    }

    private static function bundleId(string $bundleId): string
    {
        $bundleId = (string) Str::trimToNull($bundleId);

        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+$/', $bundleId) !== 1) {
            throw new ValidationException('"' . $bundleId . '" is not a bundle id: use reverse-DNS, for example com.company.app.');
        }

        return $bundleId;
    }

    private static function required(string $value, string $label): string
    {
        return Str::trimToNull($value) ?? throw new ValidationException('The ' . $label . ' is required.');
    }
}
