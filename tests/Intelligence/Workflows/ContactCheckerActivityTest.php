<?php

declare(strict_types=1);

namespace Tests\Intelligence\Workflows;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Actions\RecordPeopleNoteAction;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Workflows\Activities\ContactCheckerActivity;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;

/**
 * People own a notes channel of their own now, so a note posted on a person reaches this activity
 * with `Message::entity()` resolving to People — not the Lead the activity was written for. Calling
 * the Lead-only `setContactStatus()` on it fataled the queue worker (KANVAS-ECOSYSTEM-6CE).
 */
final class ContactCheckerActivityTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'social'];

    public function testSkipsANoteWhoseEntityIsNotALead(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        /** @var People $people */
        $people = PeopleFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create();

        $note = new RecordPeopleNoteAction($people)->execute('Called, left a voicemail', 'note', $user);

        $this->assertNotNull($note, 'fixture must produce a note in the people notes channel');
        $this->assertInstanceOf(
            People::class,
            $note->entity(),
            'fixture must reproduce production: the note resolves to People, not Lead'
        );

        $result = new ContactCheckerActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        )->execute($note, $app, []);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('Message is not associated with a Lead', $result['message']);
    }
}
