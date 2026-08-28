<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Capability;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ReportsToolOutcome;
use Kanvas\NervousSystem\Capability\Services\CapabilityLookupService;
use Kanvas\NervousSystem\Plan\Actions\RecordCapabilityGapAction;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * The action that "nobody has built this" was missing.
 *
 * `capability_lookup` can now tell an agent a capability does not exist, but a dead end is still a
 * dead end — and a model with nothing correct to do reaches for the nearest-named tool, which is the
 * exact failure the lookup was built to stop. This gives the answer somewhere to go.
 *
 * It re-runs the search itself rather than trusting that the caller did. A real turn filed a gap for
 * "recurring nurturing emails" while holding `schedule_agent_task`, whose description says
 * `recurrence_cron` — the model had the tool, did not connect it, and asked for it to be built. A gap
 * report is a roadmap item, so a false one costs someone a week.
 */
#[AgentTool(name: 'Report Capability Gap', category: 'ecosystem')]
class ReportCapabilityGapTool extends Tool implements HasRunKey
{
    use ReportsToolOutcome;
    use TrackByInputs;

    public function __construct(
        private readonly ?Agent $executor = null,
    ) {
        parent::__construct(
            name: 'report_capability_gap',
            description: 'Record that the platform has no tool for something a user asked for. Use this ONLY '
                . 'after capability_lookup came back with nothing — it is what you do instead of substituting a '
                . 'tool that merely sounds similar. It files the request for whoever owns the roadmap and tells '
                . 'you what to say to the user. It does NOT build anything. '
                . 'It searches the catalog itself before filing: if related tools exist you will be shown them '
                . 'and the report refused until you say why each one does not fit. Expect that — a capability '
                . 'is often reachable by combining tools rather than by one named for it.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'topic',
                type: PropertyType::STRING,
                description: 'The missing capability in a few plain words, e.g. "create a new Google Sheet". '
                    . 'Keep it consistent between reports so repeat requests are counted rather than duplicated.',
                required: true,
            ),
            new ToolProperty(
                name: 'context',
                type: PropertyType::STRING,
                description: 'What the user was trying to achieve, and why the existing tools do not cover it.',
                required: false,
            ),
            new ToolProperty(
                name: 'why_existing_tools_do_not_fit',
                type: PropertyType::STRING,
                description: 'Required when the platform DOES have tools that look related. Name the ones you '
                    . 'considered and say specifically why each cannot do this. If you cannot say that, one of '
                    . 'them probably can — use it instead of filing a gap.',
                required: false,
            ),
        ];
    }

    /**
     * Names of catalog tools that match the topic, granted or not — the list the model has to argue
     * against. Failing open on error: an unavailable catalog must not block a genuine gap report.
     *
     * @return list<string>
     */
    private function relatedTools(string $topic): array
    {
        if ($this->executor === null) {
            return [];
        }

        try {
            $result = new CapabilityLookupService($this->executor)->lookup($topic);
        } catch (Throwable) {
            return [];
        }

        return array_values(array_unique([
            ...array_column($result['granted'], 'name'),
            ...array_column($result['available'], 'name'),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $topic,
        ?string $context = null,
        ?string $why_existing_tools_do_not_fit = null,
    ): array {
        if ($this->executor === null) {
            return [
                'status' => 'error',
                'message' => 'No agent is in scope, so this gap cannot be attributed to anyone. Tell the user '
                    . 'the platform cannot do this yet, and mention it is not being tracked automatically.',
            ];
        }

        if (trim($topic) === '') {
            return ['status' => 'error', 'message' => 'Describe the missing capability in a few words.'];
        }

        // Search before filing, rather than trusting the description that says to. A real turn asked
        // for "recurring nurturing emails every Monday", never found `schedule_agent_task` — a tool it
        // was holding — and filed a gap for it. The check is here rather than left to the model
        // because the model is exactly what failed.
        $related = $this->relatedTools($topic);

        if ($related !== [] && trim((string) $why_existing_tools_do_not_fit) === '') {
            return [
                'status' => 'error',
                'related_tools' => $related,
                'message' => 'Not filed. The platform already has tools that look related: '
                    . implode(', ', $related) . '. Read what they do — one of them may already cover this, and '
                    . 'a tool named for one thing often does another (a scheduler is how anything becomes '
                    . 'recurring). If one of them fits but you were not granted it, that is NOT a gap: it '
                    . 'exists, so staff it — grant_agent_tools to a teammate, or hire_agent with that tool. '
                    . 'Only if none of them fit at all, call this again and use why_existing_tools_do_not_fit '
                    . 'to say why each one does not.',
            ];
        }

        try {
            $plan = new RecordCapabilityGapAction(
                agent: $this->executor,
                topic: $topic,
                context: $context,
                consideredTools: $related,
                whyNotFit: $why_existing_tools_do_not_fit,
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'Could not record the gap. Still tell the user the platform cannot do this yet — '
                    . 'do not try a different tool instead.',
            ];
        }

        $count = (int) ($plan->input['request_count'] ?? 1);

        return $this->noop(
            [
                'status' => 'success',
                'plan_id' => $plan->getId(),
                'topic' => $topic,
                'request_count' => $count,
            ],
            sprintf(
                'Recorded as a capability gap%s. Tell the user plainly that Kanvas cannot do this yet and that '
                . 'the request has been logged. Do NOT attempt it with another tool.',
                $count > 1 ? sprintf(' (asked %d times now)', $count) : '',
            ),
        );
    }
}
