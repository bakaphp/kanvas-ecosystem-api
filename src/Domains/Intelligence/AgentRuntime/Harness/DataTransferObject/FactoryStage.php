<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject;

use Baka\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\FactoryProjectSettingEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Support\TaskDirectives;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
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

    private const string RELEASE_TITLE = 'Release: ';

    private const string LEARN_TITLE = 'Learn: ';

    /** How each improvement starts in the Learn agent's answer, so the orchestrator can count them. */
    public const string IMPROVEMENT_HEADING = '## Improvement ';

    private const string LANDING_PUSH = 'git push origin HEAD:' . self::INTEGRATION_BRANCH;

    /** Any step that looks at a signed-in screen on a device needs it; left out, the agent asks a person. */
    private const string ARGENT_LOGIN = 'signed in through Argent with the test login it holds as secrets '
        . '(`{{secret:KANVAS_TEST_EMAIL}}` and `{{secret:KANVAS_TEST_PASSWORD}}`, never printed or committed)';

    /**
     * The app's own `CLAUDE.md` makes this mandatory after every change, but a builder follows the specs
     * its task names, so the task names it.
     */
    private const string APP_QA = 'Before the work leaves this branch, get the app running your change from Metro on BOTH the '
        . 'iOS simulator and the Android emulator, with the app\'s own run scripts (`yarn ios` / `yarn android`). A '
        . 'Metro, simulator, emulator or adb problem is yours to fix, not to report: repair devices with the '
        . 'factory\'s `.kanvas/device`, and remember Android reaches Metro only through `adb reverse` on the port your '
        . 'Metro uses. Then run the post-change QA the app\'s `CLAUDE.md` and `specs/` require, scoped to what you '
        . 'changed, on both platforms, asserting real outcomes and not only that a screen rendered (in apps from the '
        . 'mobile template that is the Argent sweep, `specs/qa/app-test.runbook.md`). Save the report where the specs '
        . 'say (`specs/qa/reports/` in the template) and name it in your reply. The QA runs through Argent itself, '
        . self::ARGENT_LOGIN . ', never by hand with simulator tools and '
        . 'never through a temporary login bypass in the code; this overrides any manual fallback in the app\'s own '
        . 'docs. If Argent or the test login is not available, that is not a reason to test another way: reply '
        . 'BLOCKED saying exactly what is missing. Argent is also the gate: start with its environment check, and '
        . 'treat anything it finds as yours to fix before the work goes anywhere: a red screen, a "Failed to '
        . 'compile" or bundling error (a Metro left over from another run serving the device counts: stop it and '
        . 'start your own), a crash, an error in the LogBox or in the JS and network logs (read them through '
        . 'Argent\'s log tools), or a check that did not pass. Its severity does not matter: a finding called '
        . 'minor, polish or pre-existing is fixed too. Fix it, run Argent again, and repeat until it '
        . 'reports nothing; never land the work, open a pull request or mark one ready while Argent reports an '
        . 'issue. A gap the backend has to close (an API not deployed yet) is the one thing that may stay, shown '
        . 'by the app as its unavailable state and listed under "Waiting on backend". If either platform '
        . 'still cannot run your change after you tried to fix it, reply BLOCKED with what you tried and what stops '
        . 'it: work is never finished with a platform untested, and a check that did not run is never marked passed.';

    /**
     * @param list<FactoryStep> $steps run in this order
     * @param ?string $sameStageLike a SQL LIKE pattern for an open plan that is this same stage under
     *                               another title; null means the exact title
     */
    public function __construct(
        public Project $project,
        public string $title,
        public string $description,
        public array $steps,
        public ?string $sameStageLike = null,
    ) {
    }

    /**
     * Create the app from the template, then give it its identity, then, given the approved brief, design
     * the whole app from it. Safe to start again after a stopped run: step 1 skips a repository that
     * already exists.
     *
     * A ticket designed only from its own text has no whole-app design to follow, and the app keeps the
     * template's look. The design is drawn from the approved technical plan, not from the brief alone, so
     * the mockups and the tickets describe the same screens with the same data.
     *
     * @param list<string> $designFiles the person's own design files, which win over the designer's choices
     */
    public static function kickoff(
        Project $project,
        string $repository,
        string $displayName,
        string $bundleId,
        ?string $notes = null,
        ?string $briefUrl = null,
        ?string $planUrl = null,
        array $designFiles = []
    ): self {
        $repository = self::folder($repository);
        $displayName = self::required($displayName, 'display name');
        $bundleId = self::bundleId($bundleId);
        $builder = self::builder($project);
        $template = FactoryProjectSettingEnum::templateFor($project);
        $notes = Str::trimToNull($notes);
        $briefUrl = Str::trimToNull($briefUrl);
        $planUrl = Str::trimToNull($planUrl);
        $designFiles = array_map(fn (string $file): string => self::url($file, 'design file'), $designFiles);

        if (($briefUrl === null) !== ($planUrl === null) || ($briefUrl === null && $designFiles !== [])) {
            throw new ValidationException(
                'The design needs both the approved brief (brief_url) and the approved technical plan (plan_url): '
                . 'it draws exactly the screens the plan\'s tickets build. Pass both, or neither to skip the design.'
            );
        }

        $briefUrl = $briefUrl !== null ? self::url($briefUrl, 'brief') : null;
        $planUrl = $planUrl !== null ? self::url($planUrl, 'technical plan') : null;

        $create = implode("\n", [
            'Repo: ' . $template,
            'New app: ' . $repository,
            '',
            'Kickoff 1: create the app ' . $repository . ' from the template.',
            '',
            'Steps:',
            '1. Run `.kanvas/new-app check`.',
            '2. If the repository ' . $repository . ' and its clone on this machine already exist, an earlier run created them: '
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

        $steps = [
            new FactoryStep('Kickoff 1: create ' . $repository, $create, $builder),
            new FactoryStep('Kickoff 2: configure ' . $displayName, $configure, $builder),
        ];

        if ($briefUrl !== null && $planUrl !== null) {
            $steps[] = new FactoryStep(
                'Kickoff 3: design ' . $displayName . ' from its plan',
                self::design(
                    $repository,
                    $displayName,
                    $briefUrl,
                    $planUrl,
                    $designFiles
                ),
                FactoryProjectSettingEnum::designerFor($project) ?? $builder,
            );
        }

        return new self(
            $project,
            'Kickoff: ' . $displayName . ' (' . $repository . ')',
            'Create ' . $repository . ' from ' . $template . ' and give it its identity (' . $bundleId . ')'
                . ($planUrl !== null ? ', then design every ticket of its approved plan.' : '.'),
            $steps,
            'Kickoff: %(' . Str::escapeLike($repository) . ')',
        );
    }

    /**
     * The whole app designed once, the way a product designer works from a product definition: brand and
     * theme from the brief, and a mockup of every screen the plan's tickets build, filed by ticket. A
     * mockup only shows data its ticket's Kanvas query returns, so the build can always match it. Mockups
     * are HTML rendered by headless Chrome, so they need no design tool on the Mac.
     *
     * @param list<string> $designFiles
     */
    private static function design(
        string $repository,
        string $displayName,
        string $briefUrl,
        string $planUrl,
        array $designFiles
    ): string {
        return implode("\n", array_filter([
            'Repo: ' . $repository,
            'Branch: kickoff/design',
            '',
            'Kickoff 3: design ' . $displayName . ' ticket by ticket from its approved technical plan: the one design '
                . 'every ticket is built and checked against.',
            '- The approved technical plan, the contract: ' . $planUrl,
            '- The approved brief, for the product, its users and its positioning: ' . $briefUrl,
            $designFiles !== []
                ? "- Design files the person gave. They win over your own choices: match them, and design only what they do not cover:\n"
                    . implode("\n", array_map(fn (string $file): string => '  - ' . $file, $designFiles))
                : null,
            '',
            'Steps:',
            '1. Read `CLAUDE.md`, `specs/README.md`, `specs/design-system.spec.md` and `specs/workflows/foldable.spec.md`. '
                . self::startFromIntegration(),
            '2. Download and read the whole plan, the brief and every design file with `curl -fsSL`. Design exactly the '
                . 'screens the plan\'s tickets build, and nothing else: every screen a ticket names, every state it lists '
                . '(loading, empty, error, and whatever its acceptance criteria put on screen: consent, disclaimers, '
                . 'warnings, a confirmation before a step runs, export and delete). A screen shows only the data its '
                . 'ticket\'s Kanvas query or mutation returns, under the same field names: never a value the ticket '
                . 'cannot load. When the brief has something on screen that no ticket builds, or a ticket\'s screen would '
                . 'need data its query does not return, do not design around it: list it under "Plan gaps" in the README '
                . 'and in your reply.',
            '3. The brand, from the brief\'s product, users and positioning: the palette for light and dark, typography, '
                . 'the app icon and the splash. Set them in the app the way the design-system spec says (role tokens, raw '
                . 'colours only in the colours file), and write them down in `specs/design/brand.md` with the palette in '
                . '`specs/design/theme-colors.json`.',
            '4. The screens: one mockup per screen and state for iOS and one for Android (they are different designs), '
                . 'plus the wide and foldable layouts the foldable spec asks for, built only from components the template '
                . 'has or a ticket creates. Write each as a standalone HTML page in `specs/design/src/` using the brand\'s '
                . 'tokens and fonts, with realistic content of the shape its ticket\'s data has, never lorem ipsum, and the '
                . 'screen\'s English copy. Render each at phone size with headless Chrome: '
                . '`"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --hide-scrollbars '
                . '--window-size=<width>,<height> --screenshot=<file>.png <file>.html`, into '
                . '`specs/design/{ios,android,foldables}/T<ticket>-<NN>-<screen>.png`. Open every PNG, look at it, and fix '
                . 'what is wrong before you go on.',
            '5. Write `specs/design/README.md` with one section per ticket that has screens, headed exactly '
                . '`## Ticket <number>: <short name>` in the plan\'s numbering. For each screen: its PNGs, its states, '
                . 'every piece of data it shows with the operation and field it comes from, and its copy in en and es. '
                . 'That section is what the ticket is built and checked against. End with "Plan gaps" (or "None"). Point '
                . '`specs/README.md` to it.',
            '6. ' . self::APP_QA,
            '7. ' . self::landOnIntegration(),
        ], fn (?string $line): bool => $line !== null));
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
        $slug = TaskDirectives::branchSlug($shortName);
        $branch = 'ticket/' . $number . ($slug !== '' ? '-' . $slug : '');
        $lines = 'Repo: ' . $repository . "\nBranch: " . $branch;
        $quoted = "\n\nTicket " . $number . ":\n" . $ticket;

        $mockups = self::mockupSection($number);

        $design = $lines . "\n\n"
            . self::startFromIntegration() . ' Then design ticket ' . $number . ' in code: screens, components, theme tokens, the loading/empty/error '
            . 'states, en/es copy and accessibility. When the app has ' . $mockups . ', that section is the design of '
            . 'this ticket: build its screens and states to match their mockups (layout, colours, type, spacing, '
            . 'copy), add nothing it does not show, and lay out every piece of data where the section says it comes '
            . 'from. Put your iOS and Android screenshots next to the mockups and fix every difference; reach a screen '
            . 'behind sign-in ' . self::ARGENT_LOGIN . ', and never ask a person for a login. If the ticket '
            . 'and its section disagree, the ticket text wins: build it and name the difference in your reply. Commit '
            . 'only: do not push and do not open a pull request.' . $quoted;

        $build = self::startFromIntegration() . ' '
            . ($needsDesign
                ? 'Build the logic of ticket ' . $number . ' on top of the design already committed on this branch: '
                    . 'interfaces, services, hooks, data wiring, and tests for hooks and utils. Do not redo the design. '
                    . 'When the app has ' . $mockups . ', wire each piece of data to the operation and field that '
                    . 'section names for it. Then '
                : 'Build ticket ' . $number . ': interfaces, services, hooks, data wiring, and tests for hooks and utils. '
                    . 'It has nothing on screen. Then ');

        $designCheck = $needsDesign
            ? ' When the app has ' . $mockups . ', the QA also compares each screen and state of this ticket, on both '
                . 'platforms, with its mockups there, filled with the real data: a difference is a finding like any '
                . 'other and is fixed before the work lands, unless only the backend can close it, which goes under '
                . '"Waiting on backend".'
            : '';

        $ship = $lines . "\n\n" . $build
            . self::APP_QA . $designCheck . ' Save ' . ($needsDesign ? 'iOS and Android screenshots' : 'the test output')
            . ' with the QA report: the release pull request shows them. ' . self::landOnIntegration()
            . ' The data comes from Kanvas, never '
            . 'hard-coded or dummy data: a new kind of thing the app keeps is a Kanvas message with its own '
            . 'message type (createMessage, the messages query, attachFileToMessage, tags), a single value is a '
            . 'custom field. Never change kanvas-ecosystem-api yourself. When the Kanvas the app points at lacks '
            . 'something the ticket needs (an API change not deployed yet, a message type an admin has to create), '
            . 'that is a backend gap: build everything else, keep the screen in its unavailable state and land the '
            . 'work so nothing is lost, but the ticket is NOT done. Start your reply with "BLOCKED — waiting on '
            . 'backend", then name each gap and exactly what a person has to do about it: the ticket stays blocked '
            . 'on the board until the backend landed and the feature was verified, and no release starts before '
            . 'then.' . $quoted;

        $shipStep = new FactoryStep('Ticket ' . $number . ' logic and landing: ' . $shortName, $ship, $builder);

        return new self(
            $project,
            'Ticket ' . $number . ': ' . $shortName . ' (' . $repository . ')',
            ($needsDesign ? 'Design, then logic' : 'Logic') . ' on ' . $branch . ', landed on `' . self::INTEGRATION_BRANCH . '`.',
            $needsDesign
                ? [new FactoryStep('Ticket ' . $number . ' design: ' . $shortName, $design, $designer), $shipStep]
                : [$shipStep],
            'Ticket ' . $number . ': %(' . Str::escapeLike($repository) . ')',
        );
    }

    /** Kickoff's design step writes this heading per ticket, in the plan's numbering. */
    private static function mockupSection(int $number): string
    {
        return 'the section `## Ticket ' . $number . ':` in `specs/design/README.md`';
    }

    /**
     * Learn & improve: the app already exists, so what to build next comes from what its users do and hit,
     * not from a brief. One step for the project's Learn agent, which reads the app's product analytics and
     * production errors with its own tools in Kanvas and answers with ranked improvements; the orchestrator
     * starts each as a ticket (they skip the go/no-go gate) and releases them, and the next Learn reads what
     * they did. No `Repo:` line: nothing here runs on the Mac.
     */
    public static function learn(Project $project, string $repository, ?string $notes = null): self
    {
        $repository = self::folder($repository);
        $agent = FactoryProjectSettingEnum::learnAgentFor($project) ?? throw new ValidationException(
            'This project has no Learn agent: set its `' . FactoryProjectSettingEnum::LEARN_AGENT->value
            . '` to an agent that works in Kanvas with Amplitude and Sentry tools (not a factory agent).'
        );
        $amplitude = FactoryProjectSettingEnum::learnSourceFor($project, FactoryProjectSettingEnum::LEARN_AMPLITUDE_PROJECT);
        $sentry = FactoryProjectSettingEnum::learnSourceFor($project, FactoryProjectSettingEnum::LEARN_SENTRY_PROJECT);
        $notes = Str::trimToNull($notes);
        $date = now()->toDateString();

        $text = implode("\n", array_filter([
            'Learn & improve: what should ' . $repository . ' improve next, judged from what its users do and the '
                . 'errors they hit in production. You read and decide; the orchestrator builds what you name.',
            '',
            'Sources (read only: never change a flag, an issue or any data):',
            '- Product analytics: Amplitude' . ($amplitude !== null ? ' project ' . $amplitude : '') . '.',
            '- Production errors: Sentry' . ($sentry !== null ? ' ' . $sentry : '') . ', environment production.',
            $notes !== null ? "- From the orchestrator:\n" . TaskDirectives::neutralize($notes) : null,
            '',
            'Steps:',
            '1. History first. Read this project\'s plans: every earlier "' . self::LEARN_TITLE . '" plan with its '
                . 'improvements and readings, every ticket and "' . self::RELEASE_TITLE . '" plan, and every comment '
                . 'that starts "Rejected:". Never propose what an earlier improvement already did, what is still on '
                . 'the board, or what a rejection names.',
            '2. Readings. For each released improvement whose metric you have not read at week 3 or week 6 after '
                . 'its release, and whose date has come, rerun its query unchanged on the versions since the '
                . 'release and write "## Reading: <improvement> week <3|6>: <metric> <before> -> <now>, <users>, '
                . '<date range>. Verdict: keep | iterate | revert". An iterate or revert verdict becomes an '
                . 'improvement below (a revert turns the change\'s flag off or removes it).',
            '3. Errors. List the production issues of the last 14 days, each with every version it happened on, '
                . 'newest first. Only a JS issue with in-app frames that still happens on the newest version can '
                . 'become an improvement. A warning the app logs on purpose to measure something (a watchdog, a '
                . 'timeout, a slow response) is never a fix: raising its threshold or silencing it only hides the '
                . 'measurement. A native crash, ANR or hang goes under "Needs a person", never an improvement.',
            '4. Product. Start from the outcome that matters (activation, then retention), find the step with the '
                . 'worst drop and who drops there, check the events you compare exist on every build in the window, '
                . 'and write the hypothesis as who / where / why, marking why unproven unless the data shows it. '
                . 'Next to every number give its date range and interval, and compare numbers only when both match.',
            '5. Budget. Your data tools allow few calls per turn: look names up in one search per source, answer '
                . 'each question with one grouped query, and never call a vendor\'s own agent or skill tools.',
            '',
            'Answer with up to 3 improvements, best first (users affected x confidence / size), never two on the '
                . 'same screen, each exactly in this shape:',
            self::IMPROVEMENT_HEADING . '<n>: <a few words, the ticket\'s short name>',
            '- Problem: what users hit, with the evidence (each number with its query, date range and interval).',
            '- Change: the behavior before and after, the screens, and the files when you know them; one change, '
                . 'never "and/or".',
            '- Has screens: yes | no',
            '- Flag: a remote flag key, off by default with today\'s behavior as the fallback, when the change is '
                . 'risky; else "none".',
            '- Strings: every new user-facing text in English and Spanish.',
            '- Metric: the event that proves it worked, its baseline, and the read dates (3 and 6 weeks after '
                . 'release).',
            '- Does not change: what must stay as it is.',
            'Then "Needs a person:" with anything only a person can do, if any. With nothing worth building, '
                . 'answer exactly "No improvement: <why, and what data would change that>".',
            '',
            'Never create, assign, approve or merge anything: the orchestrator turns each improvement into a ticket. '
                . 'Every number must come from a tool call you made in this task; write "unknown" for anything you '
                . 'could not fetch.',
        ], fn (?string $line): bool => $line !== null));

        return new self(
            $project,
            self::LEARN_TITLE . $repository . ' (' . $date . ')',
            'What ' . $repository . ' improves next, from its product analytics and production errors.',
            [new FactoryStep('Learn ' . $date . ': read the data, name the improvements', $text, $agent)],
            self::LEARN_TITLE . self::likeLiteral($repository) . ' (%',
        );
    }

    /** A finished Learn tells the orchestrator to start its improvements, not the next planned ticket. */
    public static function isLearn(Plan $plan): bool
    {
        return str_starts_with((string) $plan->title, self::LEARN_TITLE);
    }

    /**
     * The stage's one pull request, from the integration branch itself into `develop`, opened only after the
     * unified app was tested as a whole: each ticket was tested alone, and what breaks when they meet (a
     * conflict resolved the wrong way, one feature overwriting another) only shows on the unified branch.
     */
    public static function release(Project $project, string $repository, ?string $notes = null): self
    {
        $repository = self::folder($repository);
        $builder = self::builder($project);
        self::assertBoardIsDone($project);
        $date = now()->toDateString();
        $notes = Str::trimToNull($notes);
        $integration = self::INTEGRATION_BRANCH;

        $text = implode("\n", array_filter([
            'Repo: ' . $repository,
            'Branch: ' . $integration,
            '',
            'Release: test the unified app on `' . $integration . '` and open the one pull request of this stage, from '
                . '`' . $integration . '` into `develop`.',
            $notes !== null ? "The tickets of this stage, from the orchestrator:\n" . TaskDirectives::neutralize($notes) : null,
            '',
            'Steps:',
            '1. Read `CLAUDE.md` and the specs on commits, pull requests and the changelog. This worktree may have started '
                . 'from `develop`: run `git fetch origin` and `git reset --hard origin/' . $integration . '` (nothing of '
                . 'yours is on it yet). Merge `origin/develop` into it so the pull request has no conflict. If the branch '
                . 'has nothing `develop` lacks, reply that there is nothing to release and stop.',
            '2. Make sure no ticket\'s work was lost or overwritten when they were unified: every ticket branch of this '
                . 'stage (the ones listed above; without a list, the `origin/ticket/*` branches whose commits are in this '
                . 'history) is an ancestor of HEAD (`git merge-base --is-ancestor`); nothing is left of a conflict '
                . '(`git grep -nE "^(<<<<<<<|>>>>>>>)"`); and for each ticket, the files it changed still carry its work '
                . '(`git diff origin/<its branch> HEAD -- <its files>` shows only later, intended changes). Never merge '
                . 'a ticket branch that was not landed. Fix anything lost on this branch and commit it.',
            '3. ' . self::APP_QA . ' Here "your change" is the whole unified app: run the full app sweep '
                . '(`specs/qa/app-test.runbook.md`) and every ticket\'s own runbook together, checking that the '
                . 'features do not overlap or break each other (navigation, shared components, the data one feature '
                . 'writes and another reads). Fix what you find on this branch, commit, and test again.',
            '4. Add a changelog entry dated ' . $date . ' where the specs say (otherwise `CHANGELOG.md`, newest first): '
                . 'one line per ticket.',
            '5. A release is a finished app: every ticket\'s feature works end to end against the Kanvas the app points '
                . 'at, shown by Argent with real data (a record saved is then read back and listed). If any feature still '
                . 'waits on the backend (an API not deployed, a message type to create), the stage is not done: do not '
                . 'open a pull request or mark one ready, push `' . $integration . '`, and reply BLOCKED listing each gap '
                . 'and exactly what a person has to do; this step is sent back once they did it.',
            '6. Only when that Argent QA passed on both platforms, signed in, with every feature working: push `'
                . $integration . '` and open the pull request from it into `develop` following the app\'s specs: '
                . 'Conventional Commits from `CLAUDE.md`, `specs/workflows/pull-request.spec.md` and the PR Description '
                . 'Format of `specs/workflows/multi-app-pipeline.spec.md`. List every ticket with its QA report and '
                . 'screenshots and the unified QA report. Wait for CI, fix what you broke, and mark it ready only when '
                . 'every check is green. A person merges it.',
            '7. Reply with the pull request link, its CI state, whether it is draft or ready, and the unified QA result.',
        ], fn (?string $line): bool => $line !== null));

        return new self(
            $project,
            self::RELEASE_TITLE . $repository . ' into develop (' . $date . ')',
            'The unified app on `' . $integration . '` tested as a whole, then one pull request from it into `develop`.',
            [new FactoryStep('Release ' . $date . ': test the unified app, pull request into develop', $text, $builder)],
            self::RELEASE_TITLE . Str::escapeLike($repository) . ' into develop (%',
        );
    }

    /**
     * A release pull request says the stage is finished, so it never starts while the board says
     * otherwise. The release's own plan is left out: it is the one being started or sent back.
     */
    private static function assertBoardIsDone(Project $project): void
    {
        $open = Task::query()
            ->whereHas('plan', fn ($plan) => $plan
                ->where('project_id', $project->getId())
                ->notDeleted()
                ->where('title', 'not like', self::RELEASE_TITLE . '%'))
            ->notDeleted()
            ->whereNotIn('status', TaskStatusEnum::completedStatusValues())
            ->orderBy('id')
            ->limit(10)
            ->get(['id', 'status', 'title']);

        if ($open->isEmpty()) {
            return;
        }

        throw new ValidationException(
            'The release cannot start: these tasks are not done, and a release pull request means every ticket is '
            . "done and the board is clean.\n"
            . $open->map(fn (Task $task): string => '- #' . $task->getId() . ' ' . $task->status . ': ' . $task->title)->implode("\n")
            . "\nFinish or close them first. A ticket waiting on the backend is finished once the backend landed and it "
            . 'was verified.'
        );
    }

    private static function startFromIntegration(): string
    {
        return 'Start from the integration branch: run `git fetch origin`; if `origin/' . self::INTEGRATION_BRANCH
            . '` exists, merge it into this branch, otherwise create it from `origin/develop` with `git push origin '
            . 'origin/develop:refs/heads/' . self::INTEGRATION_BRANCH . '`.';
    }

    /** The task's own steps move the integration branch, so the next stage can start when it finishes. */
    public static function landsOnIntegration(string $taskText): bool
    {
        return str_contains($taskText, self::LANDING_PUSH);
    }

    /** A fast-forward push of the ticket branch: no checkout, no pull request, no person in the loop. */
    private static function landOnIntegration(): string
    {
        return 'Land the work on the integration branch, not in a pull request: commit following the app\'s '
            . 'Conventional Commits, run `git fetch origin` and merge `origin/' . self::INTEGRATION_BRANCH . '` into '
            . 'this branch again (another ticket may have landed), fix any conflict, re-run the app\'s type check, lint '
            . 'and unit tests, then push this branch and move the integration branch to it with `'
            . self::LANDING_PUSH . '`. If that push is rejected, fetch, merge and push again. Never push '
            . 'to `develop` and never open a pull request: the stage ends with one release pull request into '
            . '`develop`. Reply with the commit now at the tip of `' . self::INTEGRATION_BRANCH . '`, the checks you '
            . 'ran and the QA report.';
    }

    private static function builder(Project $project): Agent
    {
        return FactoryProjectSettingEnum::builderFor($project) ?? throw new ValidationException(
            'This project has no factory builder: set its `' . FactoryProjectSettingEnum::BUILDER_AGENT->value
            . '` to an agent that works on the factory machine.'
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

    /** Fetched by the agent on the Mac, never by this server, so this only keeps the task text honest. */
    private static function url(string $url, string $label): string
    {
        $url = (string) Str::trimToNull($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
            throw new ValidationException('"' . $url . '" is not a link to the ' . $label . ': give its http(s) URL, as list_task_files shows it.');
        }

        return $url;
    }

    private static function required(string $value, string $label): string
    {
        return Str::trimToNull($value) ?? throw new ValidationException('The ' . $label . ' is required.');
    }
}
