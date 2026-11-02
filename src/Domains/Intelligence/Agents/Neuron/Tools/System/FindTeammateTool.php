<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Baka\Support\Str;
use Kanvas\HumanResources\Employees\Services\EmployeeBriefService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesCompanyUserForTool;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Turns "Jenn" into someone the agent can email, DM or wait on. Searches the company's USER accounts,
 * because those are the only people the send and snooze tools accept; the HR record, when one is
 * linked, only adds the position and department.
 */
#[AgentTool(name: 'Find Teammate', category: 'ecosystem')]
class FindTeammateTool extends Tool implements RequiresSystemAgent
{
    use HasKanvasContext;
    use ResolvesCompanyUserForTool;
    use TrackByInputs;

    protected string $name = 'find_teammate';

    protected ?string $description = 'Find a teammate in this company by name, part of a name, @handle or email: their '
        . 'email, phone numbers, and their position and department when HR knows them. Call it before send_email_to_user, '
        . 'send_slack_direct_message or snooze_task when you only have a name. When several people match, '
        . 'ask which one; when nobody matches, say so — do not retry with other spellings.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'The name, part of the name, @handle or email of the teammate.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $query): array
    {
        $term = ltrim(trim($query), '@');

        if ($term === '') {
            return ['status' => 'error', 'message' => 'Say who to look for: a name, a handle or an email.'];
        }

        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('find a teammate');
        }

        $ownUserId = $this->contextAgent()?->user_id;
        $briefs = new EmployeeBriefService();

        $teammates = $this->resolveCompanyUsers($term)
            ->reject(fn (Users $user): bool => $ownUserId !== null && $user->getId() === (int) $ownUserId)
            ->map(fn (Users $user): array => $this->describe($user, $briefs))
            ->values()
            ->all();

        return [
            'query' => $query,
            'count' => count($teammates),
            'teammates' => $teammates,
            'note' => match (count($teammates)) {
                0 => "No teammate in this company matches \"{$term}\". Do not call find_teammate again with the "
                    . 'same query; tell the person you could not find them and ask for their full name or email.',
                1 => 'Use this email with send_email_to_user, send_slack_direct_message or snooze_task.',
                default => 'Several people match. Ask which one before messaging anyone.',
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Users $user, EmployeeBriefService $briefs): array
    {
        $hr = $briefs->forUser($user, $this->company, $this->app);

        return array_filter([
            'user_id' => $user->getId(),
            'name' => trim((string) $user->firstname . ' ' . (string) $user->lastname),
            'displayname' => $user->displayname,
            'email' => $user->email,
            'cell_phone' => Str::trimToNull($user->cell_phone_number),
            'phone' => Str::trimToNull($user->phone_number),
            'position' => $hr['position'] ?? null,
            'department' => $hr['department'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
