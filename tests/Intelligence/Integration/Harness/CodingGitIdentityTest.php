<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\DataTransferObject\GitIdentity;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Harness\SetHarnessCommitIdentityTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * Deploy platforms refuse a commit whose author email maps to no team member, so an agent pushing to a
 * Vercel-hosted repository has to be able to commit as someone the platform recognises.
 */
class CodingGitIdentityTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    public function testAnUnconfiguredAgentCommitsUnderItsOwnNameAndTheKanvasAddress(): void
    {
        $agent = $this->agent();

        $identity = GitIdentity::forAgent($agent);

        $this->assertSame($agent->name, $identity->name);
        $this->assertSame(GitIdentity::DEFAULT_EMAIL, $identity->email);
        $this->assertTrue($identity->isDefaultEmail());
    }

    public function testAConfiguredIdentityReplacesBoth(): void
    {
        $agent = $this->agent();
        $agent->set(AgentCustomFieldEnum::GIT_AUTHOR_NAME->value, 'Jennifer Herasme');
        $agent->set(AgentCustomFieldEnum::GIT_AUTHOR_EMAIL->value, 'jenn@example.com');

        $this->assertSame('Jennifer Herasme <jenn@example.com>', (string) GitIdentity::forAgent($agent));
    }

    public function testASessionWithNoAgentStillHasAnIdentity(): void
    {
        $this->assertSame(
            GitIdentity::DEFAULT_NAME . ' <' . GitIdentity::DEFAULT_EMAIL . '>',
            (string) GitIdentity::forAgent(null)
        );
    }

    public function testTheCommandSetsTheEmailAndKeepsTheAgentsName(): void
    {
        $agent = $this->agent();

        $this->artisan('kanvas:coding:git-identity', ['--agent' => $agent->getId(), '--email' => 'jenn@example.com'])
            ->assertSuccessful();

        $identity = GitIdentity::forAgent($agent->refresh());
        $this->assertSame('jenn@example.com', $identity->email);
        $this->assertSame($agent->name, $identity->name);
    }

    public function testTheCommandRefusesAnInvalidEmail(): void
    {
        $agent = $this->agent();

        $this->artisan('kanvas:coding:git-identity', ['--agent' => $agent->getId(), '--email' => 'not-an-email'])
            ->assertFailed();

        $this->assertTrue(GitIdentity::forAgent($agent->refresh())->isDefaultEmail());
    }

    public function testTheCommandRefusesANameGitWouldReject(): void
    {
        $agent = $this->agent();

        $this->artisan('kanvas:coding:git-identity', ['--agent' => $agent->getId(), '--name' => 'Evil <x@y.z>'])
            ->assertFailed();
    }

    public function testResetGoesBackToTheDefaults(): void
    {
        $agent = $this->agent();
        $agent->set(AgentCustomFieldEnum::GIT_AUTHOR_NAME->value, 'Jennifer Herasme');
        $agent->set(AgentCustomFieldEnum::GIT_AUTHOR_EMAIL->value, 'jenn@example.com');

        $this->artisan('kanvas:coding:git-identity', ['--agent' => $agent->getId(), '--reset' => true])
            ->assertSuccessful();

        $identity = GitIdentity::forAgent($agent->refresh());
        $this->assertSame($agent->name, $identity->name);
        $this->assertTrue($identity->isDefaultEmail());
    }

    public function testTheToolCommitsAsThePersonTalkingToTheAgent(): void
    {
        $agent = $this->agent();
        $human = $this->human();

        $result = new SetHarnessCommitIdentityTool($agent, $human)(commit_as: 'me', relaying_human_instruction: true);

        $this->assertSame('ok', $result['outcome']);
        $this->assertSame($human->email, GitIdentity::forAgent($agent->refresh())->email);
    }

    /**
     * On @mention surfaces the turn's user is the agent's own account. Committing "as me" there must not
     * quietly succeed with the agent's identity dressed up as a person's.
     */
    public function testTheToolRefusesToCommitAsTheAgentsOwnUser(): void
    {
        $agent = $this->agent();

        $result = new SetHarnessCommitIdentityTool($agent, $agent->user)(commit_as: 'me', relaying_human_instruction: true);

        $this->assertSame('denied', $result['outcome']);
        $this->assertTrue(GitIdentity::forAgent($agent->refresh())->isDefaultEmail());
    }

    public function testTheToolRefusesWithNoOneInTheConversation(): void
    {
        $agent = $this->agent();

        $result = new SetHarnessCommitIdentityTool($agent)(commit_as: 'me', relaying_human_instruction: true);

        $this->assertSame('denied', $result['outcome']);
    }

    public function testAPersonWithNoNameNeverInheritsSomebodyElsesName(): void
    {
        $agent = $this->agent();
        $agent->set(AgentCustomFieldEnum::GIT_AUTHOR_NAME->value, 'Somebody Else');

        $human = $this->human();
        $human->firstname = '';
        $human->lastname = '';
        $human->displayname = '';

        new SetHarnessCommitIdentityTool($agent, $human)(commit_as: 'me', relaying_human_instruction: true);

        $this->assertSame($human->email, GitIdentity::forAgent($agent->refresh())->name);
    }

    public function testTheToolNeedsThePersonToHaveAsked(): void
    {
        $agent = $this->agent();

        $result = new SetHarnessCommitIdentityTool($agent, $this->human())(commit_as: 'me', relaying_human_instruction: false);

        $this->assertSame('denied', $result['outcome']);
        $this->assertTrue(GitIdentity::forAgent($agent->refresh())->isDefaultEmail());
    }

    public function testTheToolCanResetToTheDefault(): void
    {
        $agent = $this->agent();
        $agent->set(AgentCustomFieldEnum::GIT_AUTHOR_EMAIL->value, 'jenn@example.com');

        $result = new SetHarnessCommitIdentityTool($agent, $this->human())(commit_as: 'default', relaying_human_instruction: true);

        $this->assertSame('ok', $result['outcome']);
        $this->assertTrue(GitIdentity::forAgent($agent->refresh())->isDefaultEmail());
    }

    /**
     * Not auth()->user(): other suites set the shared test company's AI_AGENT_USER_ID to that user and
     * company settings are not rolled back, so it may legitimately be refused as an agent account.
     */
    private function human(): Users
    {
        return Users::factory()->create();
    }

    private function agent(): Agent
    {
        return Agent::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();
    }
}
