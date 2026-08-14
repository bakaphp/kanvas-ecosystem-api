<?php

declare(strict_types=1);

namespace Tests\Guild\Leads\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

class RecordLeadNoteActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testRecordsNoteOnLeadNotesChannel(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        $note = new RecordLeadNoteAction($lead)->execute('Prospect opted out', 'opt-out');

        $this->assertNotNull($note);
        $this->assertSame(1, $note->is_public);
        $this->assertSame('Prospect opted out', $note->message['content'] ?? null);
        $this->assertTrue((bool) ($note->message['from_ia'] ?? false));
        $this->assertTrue($note->tags()->where('name', 'opt-out')->exists());

        $channel = $lead->systemNotes;
        $this->assertNotNull($channel);
        $this->assertTrue(
            $channel->messages()->where('messages.id', $note->getId())->exists()
        );
    }

    public function testNoteCanBeRecordedAsPrivate(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        $note = new RecordLeadNoteAction($lead)->execute(
            body: 'Internal only — CRM sync failed',
            tag: 'note',
            isPublic: false,
        );

        $this->assertNotNull($note);
        $this->assertSame(0, $note->is_public);
    }

    public function testTagDefaultsToNote(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        $note = new RecordLeadNoteAction($lead)->execute('Just a note');

        $this->assertNotNull($note);
        $this->assertTrue($note->tags()->where('name', 'note')->exists());
    }
}
