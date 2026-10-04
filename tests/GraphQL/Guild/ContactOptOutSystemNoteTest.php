<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

class ContactOptOutSystemNoteTest extends TestCase
{
    public function testLogsDoNotTextEnabledWhenIsOptOutFlipsOn(): void
    {
        [$contact, $lead] = $this->makePeopleWithLeadAndPhoneContact();

        $this->updateContactOptOut($contact, true);

        $message = $lead->refresh()->systemNotes->messages()->latest('messages.id')->first();
        $this->assertNotNull($message, 'Expected a system note message after enabling opt-out');
        $this->assertSame('Do Not Text enabled', $message->message['content']);
    }

    public function testLogsDoNotTextDisabledWhenIsOptOutFlipsOff(): void
    {
        [$contact, $lead] = $this->makePeopleWithLeadAndPhoneContact();

        $this->updateContactOptOut($contact, true);
        $this->updateContactOptOut($contact, false);

        $message = $lead->refresh()->systemNotes->messages()->latest('messages.id')->first();
        $this->assertNotNull($message);
        $this->assertSame('Do Not Text disabled', $message->message['content']);
    }

    public function testNoSystemNoteWhenIsOptOutNotChanged(): void
    {
        [$contact, $lead] = $this->makePeopleWithLeadAndPhoneContact();

        $messagesBefore = $lead->systemNotes?->messages()->count() ?? 0;

        $this->graphQL('
            mutation($id: ID!, $input: UpdateContactInput!) {
                updateContact(id: $id, input: $input) { id weight }
            }
        ', [
            'id' => $contact->id,
            'input' => ['weight' => 5],
        ])->assertSuccessful();

        $messagesAfter = $lead->refresh()->systemNotes?->messages()->count() ?? 0;
        $this->assertSame($messagesBefore, $messagesAfter, 'Updating weight should not emit a system note');
    }

    public function testTargetsMostRecentLeadOnly(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $response = $this->graphQL('
            mutation($input: PeopleInput!) {
                createPeople(input: $input) { id }
            }
        ', [
            'input' => [
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
                'contacts' => [[
                    'value' => '8095551234',
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ]],
                'address' => [],
                'custom_fields' => [],
            ],
        ])->json();

        $people = People::find($response['data']['createPeople']['id']);

        $olderLead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->id)
            ->create();

        $newerLead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->id)
            ->create();

        $contact = $people->contacts()->first();

        $this->updateContactOptOut($contact, true);

        $newerCount = $newerLead->refresh()->systemNotes->messages()->count();
        $olderCount = $olderLead->refresh()->systemNotes->messages()->count();

        $this->assertSame(1, $newerCount, 'Most recent lead should receive the note');
        $this->assertSame(0, $olderCount, 'Older lead should not receive the note');
    }

    private function makePeopleWithLeadAndPhoneContact(): array
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $response = $this->graphQL('
            mutation($input: PeopleInput!) {
                createPeople(input: $input) { id }
            }
        ', [
            'input' => [
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
                'contacts' => [[
                    'value' => '8095551234',
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ]],
                'address' => [],
                'custom_fields' => [],
            ],
        ])->json();

        $people = People::find($response['data']['createPeople']['id']);

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->id)
            ->create();

        $contact = $people->contacts()->first();

        return [$contact, $lead];
    }

    private function updateContactOptOut(Contact $contact, bool $isOptOut): void
    {
        $this->graphQL('
            mutation($id: ID!, $input: UpdateContactInput!) {
                updateContact(id: $id, input: $input) { id is_opt_out }
            }
        ', [
            'id' => $contact->id,
            'input' => ['is_opt_out' => $isOptOut],
        ])->assertSuccessful();
    }
}
