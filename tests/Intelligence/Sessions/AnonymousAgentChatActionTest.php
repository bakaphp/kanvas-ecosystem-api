<?php

declare(strict_types=1);

namespace Tests\Intelligence\Sessions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Sessions\Actions\AnonymousAgentChatAction;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Intelligence\ReceptionistNeuronAgentStub;
use Tests\TestCase;

class AnonymousAgentChatActionTest extends TestCase
{
    private function makeAgent(string $handler, bool $publicChat): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()->withAppId($app->getId())->create([
            'provider' => 'neuron',
            'handler' => $handler,
        ]);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'agent_type_id' => $agentType->getId(),
                'user_id' => auth()->user()->getId(),
            ]);

        if ($publicChat) {
            $agent->set('public_chat_enabled', true);
        }

        return $agent;
    }

    private function configureCompanyAiUser(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $company->set(ConfigurationEnum::AI_AGENT_USER_ID->value, auth()->user()->getId());
    }

    public function testRejectsAgentThatIsNotCustomerFacing(): void
    {
        $agent = $this->makeAgent(SystemUserAgent::class, publicChat: true);

        $this->expectException(ValidationException::class);

        new AnonymousAgentChatAction(
            app: app(Apps::class),
            agent: $agent,
            token: 'tok-not-customer',
            message: 'hi',
        )->execute();
    }

    public function testRejectsWhenPublicChatFlagDisabled(): void
    {
        $agent = $this->makeAgent(ReceptionistNeuronAgentStub::class, publicChat: false);

        $this->expectException(ValidationException::class);

        new AnonymousAgentChatAction(
            app: app(Apps::class),
            agent: $agent,
            token: 'tok-no-flag',
            message: 'hi',
        )->execute();
    }

    public function testTurnCapBlocksFurtherTurns(): void
    {
        $this->configureCompanyAiUser();
        $agent = $this->makeAgent(ReceptionistNeuronAgentStub::class, publicChat: true);
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $guest = $company->getAiAgentUserOrFail();
        $token = 'tok-capped-' . uniqid();

        Session::create([
            'uuid' => $token,
            'canal_id' => $token,
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'agents_id' => $agent->getId(),
            'entity_namespace' => Users::class,
            'entity_id' => $guest->getId(),
            'user' => ['name' => 'Guest', 'id' => $guest->getId(), 'email' => null],
            'content' => ['anon_turns' => 50],
        ]);

        $this->expectException(ValidationException::class);

        new AnonymousAgentChatAction(
            app: $app,
            agent: $agent,
            token: $token,
            message: 'one more please',
        )->execute();
    }

    public function testHappyPathRepliesCreatesSessionKeyedByTokenAndCountsTurns(): void
    {
        $this->configureCompanyAiUser();
        $agent = $this->makeAgent(ReceptionistNeuronAgentStub::class, publicChat: true);
        $app = app(Apps::class);
        $token = 'tok-live-' . uniqid();

        $result = new AnonymousAgentChatAction(
            app: $app,
            agent: $agent,
            token: $token,
            message: 'what are your hours?',
        )->execute();

        $this->assertSame($token, $result['token']);
        $this->assertNotEmpty($result['reply']);
        $this->assertSame(49, $result['turns_remaining']);

        $session = Session::query()->fromApp($app)->where('uuid', $token)->first();
        $this->assertNotNull($session);
        $this->assertSame($agent->getId(), (int) $session->agents_id);

        // Second turn resumes the same session and decrements again.
        $second = new AnonymousAgentChatAction(
            app: $app,
            agent: $agent,
            token: $token,
            message: 'do you take walk-ins?',
        )->execute();

        $this->assertSame(48, $second['turns_remaining']);
        $this->assertSame(
            1,
            Session::query()->fromApp($app)->where('uuid', $token)->count(),
            'Resuming must reuse the same session row, not create a new one.'
        );
    }

    public function testTurnCapHonorsAppSettingOverride(): void
    {
        $this->configureCompanyAiUser();
        $app = app(Apps::class);
        $app->set('public_chat_turn_cap', 2);

        try {
            $agent = $this->makeAgent(ReceptionistNeuronAgentStub::class, publicChat: true);

            $result = new AnonymousAgentChatAction(
                app: $app,
                agent: $agent,
                token: 'tok-app-cap-' . uniqid(),
                message: 'hi',
            )->execute();

            // Cap of 2 → after the first turn, one remains (not 49).
            $this->assertSame(1, $result['turns_remaining']);
        } finally {
            $app->set('public_chat_turn_cap', 0);
        }
    }
}
