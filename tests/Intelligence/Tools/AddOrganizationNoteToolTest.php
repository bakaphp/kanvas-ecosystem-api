<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\AddOrganizationNoteTool;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Tools\HasRunKey;
use Tests\TestCase;

final class AddOrganizationNoteToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testWritesTheNoteToTheAccountNotesThread(): void
    {
        $organization = $this->seedOrganization('Note Writing Corp');

        $result = $this->tool()->__invoke(
            note: 'Renewal call scheduled for the 14th — they want the analytics module demoed.',
            organization_id: $organization->getId(),
        );

        $this->assertSame('success', $result['status']);
        $this->assertStringContainsString('analytics module demoed', $this->latestNoteContent($organization));
    }

    public function testTheNoteIsAttributedToTheActingAgentUserAndTagged(): void
    {
        $organization = $this->seedOrganization('Attribution Corp');

        $this->tool()->__invoke(note: 'Contract sent.', organization_id: $organization->getId());

        $note = $this->latestNote($organization);

        $this->assertNotNull($note);
        $this->assertSame(auth()->user()->getId(), (int) $note->users_id);
        $this->assertTrue($note->tags()->where('name', 'agent-note')->exists());
    }

    public function testTheAccountCanBeIdentifiedByName(): void
    {
        $organization = $this->seedOrganization('Uniquely Named Corp');

        $result = $this->tool()->__invoke(
            note: 'Resolved by name.',
            organization_name: $organization->name,
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame($organization->getId(), $result['organization_id']);
    }

    public function testEmptyNoteIsRejected(): void
    {
        $organization = $this->seedOrganization('Empty Note Corp');

        $result = $this->tool()->__invoke(note: '   ', organization_id: $organization->getId());

        $this->assertSame('error', $result['status']);
        $this->assertNull($this->latestNote($organization));
    }

    public function testUnknownOrganizationReturnsErrorInsteadOfThrowing(): void
    {
        $result = $this->tool()->__invoke(note: 'anything', organization_id: 999999999);

        $this->assertArrayHasKey('error', $result);
    }

    public function testAToolWiredWithoutTenantContextResolvesNothing(): void
    {
        $organization = $this->seedOrganization('Unscoped Corp');

        $result = new AddOrganizationNoteTool()->__invoke(
            note: 'Should not be saved.',
            organization_id: $organization->getId(),
        );

        $this->assertSame('no_tenant_context', $result['reason']);
        $this->assertNull($this->latestNote($organization));
    }

    public function testTheRunBudgetIsKeyedPerAccountNotPerToolName(): void
    {
        $tool = new AddOrganizationNoteTool();

        $this->assertInstanceOf(HasRunKey::class, $tool);

        $keyA = $tool->setInputs(['organization_id' => 88121])->getRunKey();
        $keyB = $tool->setInputs(['organization_id' => 88122])->getRunKey();
        $keyAAgain = $tool->setInputs(['organization_id' => 88121])->getRunKey();

        $this->assertNotEquals($keyA, $keyB, 'Distinct accounts must not share a run budget.');
        $this->assertEquals($keyA, $keyAAgain, 'Identical calls must collapse to one key.');
    }

    private function tool(): AddOrganizationNoteTool
    {
        $user = auth()->user();

        return new AddOrganizationNoteTool()->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }

    private function seedOrganization(string $name): Organization
    {
        $app = app(Apps::class);
        $user = auth()->user();

        return Organization::create([
            'apps_id' => $app->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => $name . ' ' . uniqid(),
            'address' => '',
            'total_employees' => 0,
        ]);
    }

    private function latestNote(Organization $organization): ?Message
    {
        return $organization->refresh()->notes?->messages()->latest('messages.id')->first();
    }

    private function latestNoteContent(Organization $organization): string
    {
        return (string) ($this->latestNote($organization)?->message['content'] ?? '');
    }
}
