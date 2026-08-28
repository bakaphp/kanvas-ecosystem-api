<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Collection;
use Kanvas\NervousSystem\Capability\Models\Tool;

/**
 * Turns catalog tool names into rows an agent can actually be given.
 *
 * Hiring used to be bounded by what the HIRING agent held, on the reasoning that a hire holding more
 * than its hirer launders a permission nobody approved. That reading does not survive contact with an
 * orchestrator: a project manager's whole job is to staff work it cannot do itself, and grants here
 * are purely additive — nothing in the system says an agent is *forbidden* a tool, only that it was
 * never given one. So the guard blocked every useful hire while preventing no real escalation, and
 * every request for an ungranted capability dead-ended at a human.
 *
 * Three checks replace it, each failing at grant time rather than at run time:
 *  - **Runtime match.** `Create Lead` exists twice, once for Laravel and once for Neuron. Granting the
 *    wrong row is invisible — `CapabilityProvider::getActiveTools()` filters by framework, so the hire
 *    comes up holding nothing and neither end reports an error.
 *  - **Connector readiness.** A Google Sheets tool granted in a tenant with no service-account key
 *    buys an agent that fails on its first real task, days later, somewhere else.
 *  - **Non-delegable tools.** The genuine escalation boundary, and far narrower than "whatever the
 *    hirer holds": an agent may be given any ordinary capability a human authorised, and never the
 *    tools that mint or re-equip agents.
 */
class ToolGrantResolver
{
    /**
     * Tools that reshape the agent fabric itself. Keeping these with a human is what bounds fan-out
     * once hiring is no longer bounded by the hirer's own toolset — an agent that could pass on
     * hiring could staff an org chart from one instruction.
     *
     * Matched on handler class, not label: the label is editable catalog data, the class is what runs.
     *
     * @var list<string>
     */
    private const array NON_DELEGABLE = [
        'GrantAgentToolsTool',
        'HireAgentTool',
        'UpdateAgentInstructionsTool',
    ];

    private readonly ConnectorReadinessService $readiness;

    public function __construct(
        private readonly AppInterface $app,
        ?ConnectorReadinessService $readiness = null,
    ) {
        $this->readiness = $readiness ?? new ConnectorReadinessService();
    }

    /**
     * @param array<int, string>|string|null $names Catalog labels as `capability_lookup` reports them,
     *                                               or the comma-separated string an LLM tool param carries.
     * @param string|null $framework The runtime the receiving agent runs on; null skips the check.
     * @return array{tools: list<Tool>, refused: array<string, string>}
     */
    public function resolve(array|string|null $names, ?string $framework): array
    {
        $tools = [];
        $refused = [];

        foreach ($this->normalize($names) as $name) {
            $resolved = $this->resolveOne($name, $framework);

            if (is_string($resolved)) {
                $refused[$name] = $resolved;

                continue;
            }

            $tools[(int) $resolved->getKey()] = $resolved;
        }

        return ['tools' => array_values($tools), 'refused' => $refused];
    }

    /**
     * Splits a comma-separated list the model wrote, which is how every tool takes lists — a bare
     * ARRAY property makes Gemini reject the whole turn.
     *
     * @return list<string>
     */
    private function normalize(array|string|null $names): array
    {
        $list = is_array($names) ? $names : explode(',', (string) $names);

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $name): string => trim((string) $name),
            $list,
        ))));
    }

    /**
     * The refusals as one sentence for the model to read back.
     *
     * Lives here rather than on each caller because this class decides the shape of `refused`; a
     * formatter that lived beside the callers would have to be kept in step with it twice.
     *
     * @param array<string, string> $refused
     */
    public function describeRefusals(array $refused): string
    {
        $lines = [];

        foreach ($refused as $name => $reason) {
            $lines[] = sprintf('"%s" — %s', $name, $reason);
        }

        return implode(' ', $lines);
    }

    private function resolveOne(string $name, ?string $framework): Tool|string
    {
        $candidates = $this->candidates($name);

        if ($candidates->isEmpty()) {
            return sprintf(
                'No tool called "%s" is in the catalog. Run capability_lookup to get its exact name.',
                $name,
            );
        }

        $tool = $framework === null
            ? $candidates->first()
            : $candidates->first(
                fn (Tool $candidate): bool => in_array($framework, $this->frameworks($candidate), true),
            );

        if ($tool === null) {
            return sprintf(
                '"%s" exists but not for the %s runtime this agent runs on, so granting it would leave '
                . 'the agent holding nothing.',
                $name,
                (string) $framework,
            );
        }

        if (in_array(class_basename((string) $tool->handler), self::NON_DELEGABLE, true)) {
            return sprintf(
                '"%s" cannot be passed on — it creates or re-equips agents, and that stays with a human.',
                $name,
            );
        }

        $readiness = $this->readiness->forHandler($tool->handler, $this->app);

        if ($readiness !== null && ! $readiness->ready) {
            return sprintf(
                '"%s" runs on %s, which this company has not set up yet%s Granting it now buys an agent '
                . 'that fails the first time it is used.',
                $name,
                $readiness->label,
                $readiness->issues === [] ? '.' : ': ' . implode('; ', $readiness->issues) . '.',
            );
        }

        return $tool;
    }

    /**
     * @return Collection<int, Tool>
     */
    private function candidates(string $name): Collection
    {
        return Tool::query()
            ->active()
            ->fromAppOrGlobal($this->app)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->get();
    }

    /**
     * @return list<string>
     */
    private function frameworks(Tool $tool): array
    {
        return array_values(array_map(strval(...), $tool->frameworks));
    }
}
