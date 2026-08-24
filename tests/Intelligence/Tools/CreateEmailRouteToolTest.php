<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\CreateEmailRouteTool;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Tests\TestCase;

/**
 * The refusals are the point of this tool. It hands Mailgun a rule about where a tenant's real mail
 * goes, so every path that ends in "route created" has to be one the model could not have talked its
 * way into.
 */
final class CreateEmailRouteToolTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'intelligence', 'workflow'];

    public function testANonAdminCannotRouteMail(): void
    {
        $result = $this->tool(Users::factory()->create())(
            local_part: 'accounting',
            receiver_id: 1,
        );

        $this->assertFalse($result['created']);
        $this->assertStringContainsString('permission', $result['message']);
    }

    /**
     * A full address would let the agent claim routing for a domain the tenant does not own, so only
     * the local part is a parameter and anything address-shaped is refused outright.
     */
    public function testAFullAddressIsRefusedSoTheDomainStaysTheCompanys(): void
    {
        $result = $this->tool()(
            local_part: 'billing@somewhere-else.com',
            receiver_id: 1,
        );

        $this->assertFalse($result['created']);
        $this->assertStringContainsString('not a full email address', $result['message']);
    }

    public function testAReceiverFromAnotherCompanyIsNotFound(): void
    {
        $this->company()->set(ConfigurationEnum::DOMAIN->value, 'mail.example.com');

        $foreign = $this->receiverFor(Companies::factory()->create());

        $result = $this->tool()(local_part: 'accounting', receiver_id: (int) $foreign->getId());

        $this->assertFalse($result['created']);
        $this->assertStringContainsString('does not belong to this company', $result['message']);
    }

    /**
     * Routes are keyed by recipient, so the idempotent "adopt and repoint" path would take an agent's
     * personal inbox out from under it and its mail would just stop arriving.
     */
    public function testItWillNotStealAnAgentsOwnInbox(): void
    {
        $this->company()->set(ConfigurationEnum::DOMAIN->value, 'mail.example.com');

        $agent = Agent::factory()
            ->withAppId($this->app()->getId())
            ->withCompanyId($this->company()->getId())
            ->create(['name' => 'Sofia ' . fake()->unique()->lexify('?????')]);
        $agent->set(CustomFieldEnum::MAILBOX_ADDRESS->value, 'sofia-inbox@mail.example.com');

        $receiver = $this->receiverFor($this->company());

        $result = $this->tool()(local_part: 'sofia-inbox', receiver_id: (int) $receiver->getId());

        $this->assertFalse($result['created']);
        $this->assertStringContainsString('personal inbox', $result['message']);
        $this->assertStringContainsString($agent->name, $result['message']);
    }

    public function testWithoutAMailgunDomainItSaysSoRatherThanGuessing(): void
    {
        $this->company()->set(ConfigurationEnum::DOMAIN->value, '');
        $this->app()->set(ConfigurationEnum::DOMAIN->value, '');

        $result = $this->tool()(local_part: 'accounting', receiver_id: 1);

        $this->assertFalse($result['created']);
        $this->assertStringContainsString('Mailgun', $result['message']);
        $this->assertStringContainsString('do not retry', $result['message']);
    }

    private function receiverFor(Companies $company): ReceiverWebhook
    {
        return ReceiverWebhook::factory()
            ->app($this->app()->getId())
            ->user($this->currentUser()->getId())
            ->company($company->getId())
            ->create(['configuration' => []]);
    }

    private function tool(?Users $requestingUser = null): CreateEmailRouteTool
    {
        return new CreateEmailRouteTool()
            ->withContext($this->app(), $this->company(), $this->currentUser())
            ->forRequestingUser($requestingUser ?? $this->currentUser());
    }

    private function app(): Apps
    {
        return app(Apps::class);
    }

    private function company(): Companies
    {
        return $this->currentUser()->getCurrentCompany();
    }

    private function currentUser(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }
}
