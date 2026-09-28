<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\CustomerSuccess;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Github\Enums\ConfigurationEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Enums\KanvasReleaseFeedEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\CustomerSuccess\CustomerUpdateAgent;
use Tests\TestCase;

final class DraftCustomerUpdateCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence'];

    /**
     * No releases in the window means the action short-circuits before any provider call, so the whole
     * command runs end to end here without a live LLM.
     */
    public function testReportsNothingToSendWhenNoReleasesInTheWindow(): void
    {
        Http::fake(['api.github.com/*' => Http::response([])]);
        $organization = $this->seedOrganization();
        $this->seedAgent($organization);

        $this->artisan('kanvas:customer-success:draft-update', [
            '--app_id' => app(Apps::class)->getId(),
            '--organization_id' => $organization->getId(),
        ])
            ->expectsOutputToContain('Nothing to send')
            ->assertSuccessful();
    }

    public function testFailsClearlyWhenTheCompanyHasNoCustomerUpdateAgent(): void
    {
        Http::fake(['api.github.com/*' => Http::response([])]);
        $organization = $this->seedOrganization();

        $this->artisan('kanvas:customer-success:draft-update', [
            '--app_id' => app(Apps::class)->getId(),
            '--organization_id' => $organization->getId(),
        ])
            ->expectsOutputToContain('No Customer Update Agent')
            ->assertFailed();
    }

    /**
     * A draft with no account notes is a generic email. Warn rather than fail — the operator may be
     * checking the plumbing before writing the notes.
     */
    public function testWarnsWhenTheAccountHasNoNotesChannel(): void
    {
        Http::fake(['api.github.com/*' => Http::response([])]);
        $organization = $this->seedOrganization();
        $organization->notes?->forceDelete();
        $this->seedAgent($organization->refresh());

        $this->artisan('kanvas:customer-success:draft-update', [
            '--app_id' => app(Apps::class)->getId(),
            '--organization_id' => $organization->getId(),
        ])
            ->expectsOutputToContain('no notes channel')
            ->assertSuccessful();
    }

    private function seedOrganization(): Organization
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::TOKEN->value, 'test-token');
        $app->set(KanvasReleaseFeedEnum::REPOSITORIES->value, 'acme/api');

        return Organization::create([
            'apps_id' => $app->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => 'Command Corp ' . uniqid(),
            'address' => '',
            'total_employees' => 0,
        ]);
    }

    private function seedAgent(Organization $organization): Agent
    {
        $type = AgentType::query()->where('handler', CustomerUpdateAgent::class)->firstOrFail();

        return Agent::factory()->create([
            'apps_id' => $organization->apps_id,
            'companies_id' => $organization->companies_id,
            'user_id' => $organization->users_id,
            'agent_type_id' => $type->getId(),
        ]);
    }
}
