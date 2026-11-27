<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Company\CompanyBrainAgent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Capability\Models\Tool as CatalogTool;
use Kanvas\NervousSystem\Plan\Actions\UpdatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelData;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\ToolInterface;
use ReflectionMethod;

/**
 * A Company Brain in the acting user's company, and nothing left over from another test in its way:
 * the locator answers with the oldest Brain of the tenant, so a stray one would be picked instead.
 * Needs `mysql` and `intelligence` in `$connectionsToTransact`.
 */
trait MakesCompanyBrain
{
    protected Apps $tenantApp;
    protected Companies $company;
    protected Users $human;
    protected AgentType $brainType;
    protected Agent $brain;

    /**
     * @param class-string<CompanyBrainAgent> $handler the Brain itself, or a stub of it that answers offline
     */
    protected function bootCompanyBrain(string $handler = CompanyBrainAgent::class): void
    {
        $this->tenantApp = app(Apps::class);
        /** @var Users $human */
        $human = auth()->user();
        $this->human = $human;
        $this->company = $human->getCurrentCompany();

        $this->brainType = AgentType::factory()
            ->withAppId($this->tenantApp->getId())
            ->create([
                'provider' => 'neuron',
                'handler' => $handler,
            ]);

        // Range updates hold gap locks that deadlock other workers inserting their own fixtures.
        $existingAgentIds = Agent::query()
            ->fromApp($this->tenantApp)
            ->fromCompany($this->company)
            ->whereIn('agent_type_id', AgentType::query()->where('handler', CompanyBrainAgent::class)->select('id'))
            ->pluck('id');

        Agent::query()->whereKey($existingAgentIds)->update(['is_deleted' => 1]);

        $existingProjectIds = Project::query()
            ->fromApp($this->tenantApp)
            ->fromCompany($this->company)
            ->companyOperations()
            ->pluck('id');

        Project::query()->whereKey($existingProjectIds)->update(['is_deleted' => 1]);

        $this->brain = $this->makeBrainAgent();
    }

    protected function makeBrainAgent(): Agent
    {
        return Agent::factory()
            ->withAppId($this->tenantApp->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'agent_type_id' => $this->brainType->getId(),
                'name' => 'Company Brain',
            ]);
    }

    /**
     * The real Company Brain handler, configured the way the kernel configures it for one turn.
     */
    protected function brainHandler(
        Users $user,
        ?Session $session = null,
        ?Model $entity = null,
        ?Users $conversationHuman = null,
        bool $unattended = false,
    ): CompanyBrainAgent {
        $handler = new CompanyBrainAgent();
        $handler->setConfiguration($this->brain, $entity, $user);
        $handler->setSession($session);
        $handler->setUnattendedTurn($unattended);

        if ($conversationHuman !== null) {
            $handler->setConversationHuman($conversationHuman);
        }

        return $handler;
    }

    /**
     * @return list<ToolInterface>
     */
    protected function brainTools(CompanyBrainAgent $handler): array
    {
        $tools = new ReflectionMethod($handler, 'tools')->invoke($handler);

        return array_values(array_filter($tools, fn (object $tool): bool => $tool instanceof ToolInterface));
    }

    /**
     * @return list<string>
     */
    protected function toolNames(CompanyBrainAgent $handler): array
    {
        return array_map(fn (ToolInterface $tool): string => $tool->getName(), $this->brainTools($handler));
    }

    protected function tool(CompanyBrainAgent $handler, string $name): ToolInterface
    {
        foreach ($this->brainTools($handler) as $tool) {
            if ($tool->getName() === $name) {
                return $tool;
            }
        }

        $this->fail("Company Brain was not given {$name} on this turn.");
    }

    /**
     * A catalog grant, as an admin makes one: the registry then resolves its own copy of the tool.
     *
     * @param list<class-string> $handlers
     */
    protected function grantFromCatalog(Agent $agent, array $handlers): void
    {
        foreach ($handlers as $handler) {
            $row = CatalogTool::create([
                'apps_id' => $this->tenantApp->getId(),
                'name' => Str::snake(class_basename($handler)) . '_' . uniqid(),
                'description' => 'Fixture grant.',
                'tool_type' => 'system',
                'handler' => $handler,
                'frameworks' => ['neuron'],
                'version' => '1.0.0',
                'is_active' => 1,
                'is_deleted' => 0,
            ]);
            $agent->selectedTools()->attach($row->getKey());
        }
    }

    protected function channelFor(Users $owner, string $name): Channel
    {
        return new CreateChannelAction(
            new ChannelData(
                apps: $this->tenantApp,
                companies: $this->company,
                users: $owner,
                entity_id: (int) $owner->getKey(),
                entity_namespace: Users::class,
                name: $name . ' ' . fake()->unique()->lexify('?????'),
                slug: (string) Str::uuid(),
            ),
        )->execute();
    }

    /**
     * A conversation with the Brain. $storedUser is who the session row says it belongs to: the person
     * themselves in a DM, somebody else on a shared channel.
     */
    protected function sessionWithBrain(Users $storedUser, ?Channel $channel = null): Session
    {
        $channel ??= $this->channelFor($storedUser, 'DM');

        return Session::create([
            'uuid' => 'ai-assist-' . fake()->unique()->lexify('??????'),
            'apps_id' => $this->tenantApp->getId(),
            'companies_id' => $this->company->getId(),
            'entity_namespace' => Users::class,
            'entity_id' => $storedUser->getId(),
            'agents_id' => $this->brain->getId(),
            'channel_id' => $channel->getId(),
            'content' => '',
            'user' => ['id' => $storedUser->getId()],
        ]);
    }

    protected function operationsBoards(): Builder
    {
        return Project::query()
            ->fromApp($this->tenantApp)
            ->fromCompany($this->company)
            ->notDeleted()
            ->companyOperations();
    }

    /**
     * A project another agent runs, as opposed to the Brain's own board.
     */
    protected function projectManagedBy(Agent $pm): Project
    {
        return new CreateProjectAction(
            ProjectData::from(
                $this->tenantApp,
                $this->human,
                $this->company,
                [
                    'title' => 'Website ' . fake()->unique()->lexify('?????'),
                    'objective' => 'Ship the site',
                    'agent_id' => $pm->getId(),
                ],
            ),
        )->execute()->refresh();
    }

    /**
     * Through the action, so the observer and the outcome listener both fire.
     */
    protected function movePlanTo(Plan $plan, PlanStatusEnum $status): void
    {
        new UpdatePlanAction(
            $plan,
            PlanData::forUpdate(
                $plan,
                $this->tenantApp,
                $this->company,
                ['status' => $status->value],
            ),
        )->execute();
    }

    protected function messagesIn(Session $session, ?string $containing = null): int
    {
        return DB::connection('social')
            ->table('channel_messages')
            ->join(
                'messages',
                'messages.id',
                '=',
                'channel_messages.messages_id',
            )
            ->where('channel_messages.channel_id', $session->channel_id)
            ->when($containing !== null, fn ($query) => $query->where('messages.message', 'like', '%' . $containing . '%'))
            ->count();
    }
}
