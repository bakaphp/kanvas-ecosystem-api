<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ArtifactsTool;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Tests\TestCase;

class NeuronArtifactsToolTest extends TestCase
{
    public function testAllNotesAreReturnedNewestFirstWithAuthorAndDate(): void
    {
        $lead = $this->lead();
        $notes = [];
        for ($i = 0; $i < 12; $i++) {
            $notes[] = $this->note($lead, ['content' => 'Note ' . $i], [
                'created_at' => now()->subMinutes(12 - $i),
            ]);
        }
        $notes[11]->message = ['text' => 'Please speak English because his wife only speaks English.'];
        $notes[11]->saveQuietly();

        $result = $this->tool()($lead->getId());
        $actual = $result['crm']['last_notes'];

        $this->assertCount(12, $actual);
        $this->assertSame(array_reverse(array_map(fn ($note) => $note->getId(), $notes)), array_column($actual, 'id'));
        $this->assertSame('Please speak English because his wife only speaks English.', $actual[0]['content']);
        $this->assertSame(auth()->user()->getId(), $actual[0]['author']['id']);
        $this->assertSame(auth()->user()->displayname, $actual[0]['author']['name']);
        $this->assertSame($notes[11]->created_at->toIso8601String(), $actual[0]['created_at']);
    }

    public function testNotesExcludeOtherLeadsTenantsAppsDeletedAndOtherMessageTypes(): void
    {
        $lead = $this->lead();
        $otherLead = $this->lead();
        $expected = $this->note($lead, ['content' => 'Use English.']);
        $this->note($otherLead, ['content' => 'Another lead']);
        $this->note($lead, ['content' => 'Deleted'], ['is_deleted' => 1]);
        $this->note($lead, ['content' => 'Foreign company'], ['companies_id' => Companies::factory()->create()->getId()]);
        $foreignAppNote = $this->note($lead, ['content' => 'Mismatched app']);
        $foreignAppNote->apps_id = 0;
        $foreignAppNote->saveQuietly();
        $this->note($lead, ['content' => 'SMS'], [
            'message_types_id' => MessageTypeService::getOrCreate(app(Apps::class), 'twilio-sms')->getId(),
        ]);
        // A People-only note must not leak into every opportunity for that person.
        $personNote = $this->note($otherLead, ['content' => 'People only']);
        $personNote->appModuleMessages()->delete();
        $personNote->addEntity($lead->people);

        $this->assertSame([$expected->getId()], array_column($this->tool()($lead->getId())['crm']['last_notes'], 'id'));
        $this->assertSame([], $this->tool()($this->lead()->getId())['crm']['last_notes']);
    }

    private function tool(): ArtifactsTool
    {
        $user = auth()->user();

        return new ArtifactsTool()->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }

    private function lead(): Lead
    {
        return Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();
    }

    private function note(Lead $lead, array $content, array $overrides = []): Message
    {
        $type = MessageTypeService::getOrCreate(app(Apps::class), 'notes');
        $note = Message::factory()->create(array_merge([
            'apps_id' => $lead->apps_id,
            'companies_id' => $lead->companies_id,
            'users_id' => auth()->user()->getId(),
            'message_types_id' => $type->getId(),
            'message' => $content,
            'is_deleted' => 0,
        ], $overrides));
        $note->addEntity($lead);

        return $note;
    }
}
