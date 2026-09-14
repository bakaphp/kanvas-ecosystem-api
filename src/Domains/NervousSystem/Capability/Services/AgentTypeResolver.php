<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Models\AgentType;

/**
 * The agent types a hire can actually be built on.
 *
 * `hire_agent` used one hardcoded type, so every hire was a Generic Neuron Agent whatever the work
 * needed — and the orchestrator, asked for a developer, correctly reported that what it could hire
 * cannot push commits, while `Claude Agent` and `pi.dev Programming Agent` sat unused beside it.
 *
 * A type is only offered when it has a handler class that exists and names its runtime. The table also
 * holds fixtures and half-configured rows: hiring onto a missing handler produces an agent that fatals
 * the first time it runs, and the runtime is what `ToolGrantResolver` matches grants against — without
 * one, tools built for a different framework are granted and the hire comes up holding nothing.
 */
class AgentTypeResolver
{
    /** @var Collection<int, AgentType>|null */
    private ?Collection $hireable = null;

    public function __construct(
        private readonly AppInterface $app,
    ) {
    }

    /**
     * @return Collection<int, AgentType>
     */
    public function hireable(): Collection
    {
        // Every other method funnels through here, and one `list_agent_types` call reaches it four
        // times — the query plus a `class_exists` sweep, repeated per caller.
        return $this->hireable ??= $this->queryHireable();
    }

    /**
     * @return Collection<int, AgentType>
     */
    private function queryHireable(): Collection
    {
        return AgentType::query()
            ->fromAppOrGlobal($this->app)
            ->notDeleted()
            ->where('is_active', 1)
            ->whereNotNull('handler')
            ->where('handler', '!=', '')
            ->whereNotNull('provider')
            ->where('provider', '!=', '')
            ->orderBy('name')
            ->get()
            ->filter(fn (AgentType $type): bool => class_exists($type->handler))
            ->values();
    }

    /**
     * The named type, or null when it is not one a hire can be built on.
     */
    public function resolve(?string $name): ?AgentType
    {
        $name = mb_strtolower(trim((string) $name));

        if ($name === '') {
            return null;
        }

        return $this->hireable()
            ->first(fn (AgentType $type): bool => mb_strtolower($type->name) === $name);
    }

    /**
     * The default when a caller names none — a general-purpose agent whose behaviour is its
     * instructions rather than code, so it fits any job the hirer can describe.
     */
    public function default(): ?AgentType
    {
        return $this->resolve('Generic Neuron Agent') ?? $this->hireable()->first();
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->hireable()->pluck('name')->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function describe(): array
    {
        return $this->hireable()
            ->toBase()
            ->map(fn (AgentType $type): array => array_filter(
                [
                    'name' => $type->name,
                    'runtime' => $type->provider,
                    'description' => $type->description,
                    'requires_setup' => $this->requirementsOf($type),
                ],
                static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
            ))
            ->all();
    }

    /**
     * What a human must still set on a hire of this type before it can do its job.
     *
     * Read off the handler class rather than the catalog row: `sync-agent-types` deliberately never
     * refreshes an existing type's `config`, so a requirement stored there would never reach the rows
     * that already exist. The class is the one copy that cannot drift.
     *
     * @return list<string>
     */
    public function requirementsOf(AgentType $type): array
    {
        if (! class_exists($type->handler)) {
            return [];
        }

        return array_values(array_map(strval(...), AgentTypeDefinition::fromClass($type->handler)?->requires ?? []));
    }

    /**
     * Types whose connector this company has not switched on.
     *
     * A `claude` type needs the `claude-agent` integration; hiring onto one without it produces an
     * agent that exists, accepts work and cannot run. Only providers with a known integration are
     * checked — an unlisted one is left alone rather than guessed at.
     *
     * @param list<string> $activeIntegrations
     * @return list<string>
     */
    public function unavailableFor(array $activeIntegrations): array
    {
        $needed = ['claude' => 'claude-agent'];
        $active = array_map(mb_strtolower(...), $activeIntegrations);

        return $this->hireable()
            ->filter(function (AgentType $type) use ($needed, $active): bool {
                $integration = $needed[mb_strtolower($type->provider)] ?? null;

                return $integration !== null && ! in_array($integration, $active, true);
            })
            ->pluck('name')
            ->all();
    }
}
