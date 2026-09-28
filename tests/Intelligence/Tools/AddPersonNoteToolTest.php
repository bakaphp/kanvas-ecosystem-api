<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\AddPersonNoteTool;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Tools\HasRunKey;
use Tests\TestCase;

final class AddPersonNoteToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testWritesTheNoteToTheContactNotesThread(): void
    {
        $person = $this->seedPeople();

        $result = $this->tool()->__invoke(
            person_id: $person->getId(),
            note: 'Asked for the renewal quote in USD, not DOP.',
        );

        $this->assertSame('success', $result['status']);
        $this->assertStringContainsString(
            'Asked for the renewal quote in USD, not DOP.',
            $this->latestNoteContent($person),
        );
    }

    public function testTheNoteIsAttributedToTheActingAgentUserAndTagged(): void
    {
        $person = $this->seedPeople();

        $this->tool()->__invoke(person_id: $person->getId(), note: 'Left a voicemail.');

        $note = $this->latestNote($person);

        $this->assertNotNull($note);
        $this->assertSame(auth()->user()->getId(), (int) $note->users_id);
        $this->assertTrue($note->tags()->where('name', 'agent-note')->exists());
    }

    /**
     * The conversation channel is replayed to the LLM as agent history, so a teammate note landing
     * there would read back as something the agent itself said.
     */
    public function testTheNoteNeverLandsInThePeopleConversationChannel(): void
    {
        $person = $this->seedPeople();

        $this->tool()->__invoke(person_id: $person->getId(), note: 'Internal: do not quote yet.');

        $notes = $person->refresh()->notes;

        $this->assertNotNull($notes);
        $this->assertNotSame('people-channel-' . $person->getId(), $notes->slug);
    }

    public function testEmptyNoteIsRejected(): void
    {
        $person = $this->seedPeople();

        $result = $this->tool()->__invoke(person_id: $person->getId(), note: '   ');

        $this->assertSame('error', $result['status']);
        $this->assertNull($this->latestNote($person));
    }

    public function testUnknownPersonReturnsErrorInsteadOfThrowing(): void
    {
        $result = $this->tool()->__invoke(person_id: 999999999, note: 'anything');

        $this->assertArrayHasKey('error', $result);
    }

    public function testAToolWiredWithoutTenantContextResolvesNothing(): void
    {
        $person = $this->seedPeople();

        $result = new AddPersonNoteTool()->__invoke(person_id: $person->getId(), note: 'Should not be saved.');

        $this->assertSame('no_tenant_context', $result['reason']);
        $this->assertNull($this->latestNote($person));
    }

    public function testTheRunBudgetIsKeyedPerContactNotPerToolName(): void
    {
        $tool = new AddPersonNoteTool();

        $this->assertInstanceOf(HasRunKey::class, $tool);

        $keyA = $tool->setInputs(['person_id' => 4211])->getRunKey();
        $keyB = $tool->setInputs(['person_id' => 4212])->getRunKey();
        $keyAAgain = $tool->setInputs(['person_id' => 4211])->getRunKey();

        $this->assertNotEquals($keyA, $keyB, 'Distinct contacts must not share a run budget.');
        $this->assertEquals($keyA, $keyAAgain, 'Identical calls must collapse to one key.');
    }

    private function tool(): AddPersonNoteTool
    {
        $user = auth()->user();

        return new AddPersonNoteTool()->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }

    private function seedPeople(): People
    {
        $app = app(Apps::class);
        $user = auth()->user();

        /** @var People $people */
        $people = PeopleFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create();

        return $people;
    }

    private function latestNote(People $person): ?Message
    {
        return $person->refresh()->notes?->messages()->latest('messages.id')->first();
    }

    private function latestNoteContent(People $person): string
    {
        return (string) ($this->latestNote($person)?->message['content'] ?? '');
    }
}
