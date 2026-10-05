<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Harness\ReplyToHarnessPullRequestTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Mockery;
use NeuronAI\Tools\TrackByInputs;
use ReflectionClass;
use ReflectionProperty;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class GuardsRepeatCallsTest extends TestCase
{
    private const string TOOLS_PATH = 'src/Domains/Intelligence/Agents/Neuron/Tools';

    /**
     * Guarded tools the loop below cannot build itself. Each one gets its own test rather than an
     * exemption, so a dependency never becomes a way out of the coverage.
     *
     * @var list<class-string>
     */
    private const array TOOLS_BUILT_EXPLICITLY = [ReplyToHarnessPullRequestTool::class];

    public function test_the_second_identical_call_does_not_execute_the_work(): void
    {
        $tool = new RepeatGuardedProbe();

        $tool->run(['name' => 'acme']);
        $tool->run(['name' => 'acme']);

        $this->assertSame(1, $tool->executions);
    }

    /** The stop has to teach — a silent cache is what the run budget already does badly. */
    public function test_the_repeat_tells_the_model_the_answer_will_not_change(): void
    {
        $tool = new RepeatGuardedProbe();

        $tool->run(['name' => 'acme']);
        $second = $tool->run(['name' => 'acme']);

        $this->assertTrue($second['repeat_call']);
        $this->assertSame(ToolOutcomeEnum::NOOP->value, $second['outcome']);
        $this->assertStringContainsString('do NOT call this again', $second['note']);
    }

    /** The first call's answer is returned again, not replaced by an error. */
    public function test_the_repeat_still_carries_the_original_result(): void
    {
        $tool = new RepeatGuardedProbe();

        $tool->run(['name' => 'acme']);
        $second = $tool->run(['name' => 'acme']);

        $this->assertSame('acme', $second['found']);
    }

    public function test_different_arguments_run_again(): void
    {
        $tool = new RepeatGuardedProbe();

        $tool->run(['name' => 'acme']);
        $tool->run(['name' => 'globex']);

        $this->assertSame(2, $tool->executions);
    }

    /** Argument order and empty-vs-absent must not make one call look like two. */
    public function test_argument_order_and_empty_values_do_not_defeat_the_guard(): void
    {
        $tool = new RepeatGuardedProbe();

        $tool->run(['name' => 'acme', 'limit' => 5]);
        $tool->run(['limit' => 5, 'name' => 'acme', 'note' => '']);

        $this->assertSame(1, $tool->executions);
    }

    /**
     * The whole guard hangs on this. NeuronAI never invokes the registered tool — it hands each call a
     * shallow `clone` (`ToolNode::resolveTool`), which shares the ledger OBJECT but would copy a
     * null. A ledger built lazily is therefore built on the clone, every call gets its own empty one,
     * and the guard silently never fires. That is how it shipped, unused, before KANVAS-ECOSYSTEM-6A1.
     */
    public function test_the_guard_still_holds_across_the_clones_neuron_makes_per_call(): void
    {
        $registered = new RepeatGuardedProbe();

        $firstCall = clone $registered;
        $secondCall = clone $registered;

        $firstCall->run(['name' => 'acme']);
        $second = $secondCall->run(['name' => 'acme']);

        $this->assertArrayHasKey('repeat_call', $second, 'The ledger did not survive the clone.');
        $this->assertSame(0, $secondCall->executions, 'The second clone re-ran the work.');
    }

    public function test_every_guarded_tool_initialises_its_ledger_in_the_constructor(): void
    {
        $guarded = $this->guardedTools();

        $this->assertNotEmpty($guarded, 'No tool uses GuardsRepeatCalls — did the trait lose its last caller?');

        foreach ($guarded as $class) {
            if (in_array($class, self::TOOLS_BUILT_EXPLICITLY, true)) {
                continue;
            }

            $constructor = new ReflectionClass($class)->getConstructor();

            $this->assertNotNull($constructor, $class . ' uses GuardsRepeatCalls but has no constructor to init it.');
            $this->assertSame(
                0,
                $constructor->getNumberOfRequiredParameters(),
                $class . ' takes constructor arguments; add it to this test explicitly so the guard stays covered.',
            );

            $tool = new $class();

            $this->assertTrue(
                new ReflectionProperty($tool, 'repeatLedger')->isInitialized($tool),
                $class . ' must call initRepeatGuard() in its constructor — see '
                    . 'test_the_guard_still_holds_across_the_clones_neuron_makes_per_call for why it cannot be lazy.',
            );
        }
    }

    /**
     * A write to one shared destination is a repeat whatever the payload says — otherwise a model
     * narrating its progress notifies the reviewer once per sentence.
     */
    public function test_a_narrowed_key_makes_a_different_payload_to_the_same_destination_a_repeat(): void
    {
        $tool = new NarrowKeyProbe();

        $tool->post(571, 'Acknowledging receipt.');
        $second = $tool->post(571, 'Status update: inspected the CI checks.');

        $this->assertSame(1, $tool->executions, 'The second comment was posted.');
        $this->assertTrue($second['repeat_call']);
        $this->assertStringContainsString('One per round', $second['note']);
    }

    public function test_a_narrowed_key_still_lets_a_different_destination_through(): void
    {
        $tool = new NarrowKeyProbe();

        $tool->post(571, 'Fixed the null check.');
        $tool->post(572, 'Fixed the null check.');

        $this->assertSame(2, $tool->executions);
    }

    /**
     * The guard's claim is "that already happened". Saying it about a post the provider refused tells
     * the model the reviewer was answered when nobody was — the one thing `ReportsToolOutcome` exists
     * to prevent.
     */
    public function test_a_failed_write_is_not_remembered_so_it_can_be_retried(): void
    {
        $tool = new NarrowKeyProbe();
        $tool->fail = true;

        $tool->post(571, 'Fixed the null check.');
        $second = $tool->post(571, 'Fixed the null check.');

        $this->assertSame(2, $tool->executions);
        $this->assertArrayNotHasKey('repeat_call', $second);
    }

    public function test_a_read_still_remembers_a_miss_by_default(): void
    {
        $tool = new NarrowKeyProbe();
        $tool->fail = true;
        $tool->rememberFailures = true;

        $tool->post(571, 'anything');
        $tool->post(571, 'anything');

        $this->assertSame(1, $tool->executions);
    }

    public function test_the_pull_request_reply_tool_initialises_its_ledger(): void
    {
        $tool = new ReplyToHarnessPullRequestTool(Mockery::mock(Agent::class)->makePartial());

        $this->assertTrue(new ReflectionProperty($tool, 'repeatLedger')->isInitialized($tool));
    }

    /**
     * `TrackByInputs` keys the run budget by arguments, which hands every distinct comment body a
     * fresh budget of its own — the exact hole that let one review round collect four comments. The
     * two are mutually exclusive on a tool that writes to a shared thread, so assert it stays off.
     */
    public function test_the_pull_request_reply_tool_does_not_key_its_budget_by_the_comment_text(): void
    {
        $this->assertNotContains(
            TrackByInputs::class,
            class_uses(ReplyToHarnessPullRequestTool::class),
            'reply_to_coding_pull_request must not use TrackByInputs — see its class docblock.',
        );
    }

    /**
     * @return array<int, class-string>
     */
    private function guardedTools(): array
    {
        $files = new Finder()
            ->files()
            ->in(base_path(self::TOOLS_PATH))
            ->name('*.php')
            ->notPath('Traits')
            ->contains('use GuardsRepeatCalls;');

        return array_values(array_map(
            static fn (SplFileInfo $file): string => 'Kanvas\\Intelligence\\Agents\\Neuron\\Tools\\'
                . str_replace('/', '\\', $file->getRelativePath())
                . ($file->getRelativePath() === '' ? '' : '\\')
                . $file->getBasename('.php'),
            iterator_to_array($files),
        ));
    }
}

class RepeatGuardedProbe
{
    use GuardsRepeatCalls;

    public int $executions = 0;

    /**
     * Models the real contract: a guarded tool builds its ledger in the constructor, because that is
     * the only point that runs before NeuronAI starts cloning it per call.
     */
    public function __construct()
    {
        $this->initRepeatGuard();
    }

    /**
     * @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    public function run(array $inputs): array
    {
        return $this->oncePerTurn($inputs, function () use ($inputs): array {
            $this->executions++;

            return ['found' => $inputs['name'] ?? null];
        });
    }
}

class NarrowKeyProbe
{
    use GuardsRepeatCalls;

    public int $executions = 0;

    public bool $fail = false;

    public bool $rememberFailures = false;

    public function __construct()
    {
        $this->initRepeatGuard();
    }

    /**
     * @return array<string, mixed>
     */
    public function post(int $jobId, string $comment): array
    {
        return $this->oncePerTurn(
            ['job_id' => $jobId],
            function () use ($jobId, $comment): array {
                $this->executions++;

                return ['success' => ! $this->fail, 'job_id' => $jobId, 'comment' => $comment];
            },
            note: 'You have already commented on this pull request in this turn. One per round is the limit.',
            rememberFailures: $this->rememberFailures,
        );
    }
}
