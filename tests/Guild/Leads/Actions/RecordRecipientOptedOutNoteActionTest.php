<?php

declare(strict_types=1);

namespace Tests\Guild\Leads\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Actions\RecordRecipientOptedOutNoteAction;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class RecordRecipientOptedOutNoteActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'intelligence', 'social'];

    public function testTheAttemptIsLeftOnTheLeadAsAPrivateNote(): void
    {
        $lead = Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), auth()->user()->getCurrentCompany()->getId())
            ->create();

        $note = new RecordRecipientOptedOutNoteAction($lead)->execute('+18095550900', 'Are you still interested?');

        $this->assertNotNull($note);
        $this->assertSame(0, $note->is_public);
        $this->assertStringContainsString('+18095550900 has opted out of messages', $note->message['content']);
        $this->assertStringContainsString('Are you still interested?', $note->message['content']);
        $this->assertTrue($note->tags()->where('name', RecordRecipientOptedOutNoteAction::TAG)->exists());
        $this->assertTrue($lead->systemNotes->messages()->where('messages.id', $note->getId())->exists());
    }
}
