<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Leads\Contact;
use Kanvas\Connectors\VinSolution\Services\ContactRejectionService;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class ContactRejectionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testUpdatePayloadNeverEchoesBackTheEmailsVinSolutionAlreadyHas(): void
    {
        $contact = $this->vinContact();
        // Every local email was flagged undeliverable, so we have nothing to submit.
        $contact->emails = [];

        $payload = $contact->buildUpdatePayload(19236, 1543430);

        $this->assertArrayNotHasKey(
            'Emails',
            $payload['ContactInformation'],
            'echoing VinSolutions its own rejected address is what made the PUT 400 on every retry'
        );
    }

    public function testUpdatePayloadNeverEchoesBackThePhonesVinSolutionAlreadyHas(): void
    {
        $contact = $this->vinContact();
        $contact->phones = [];

        $payload = $contact->buildUpdatePayload(19236, 1543430);

        $this->assertArrayNotHasKey('Phones', $payload['ContactInformation']);
    }

    public function testUpdatePayloadStillSendsChangedContactData(): void
    {
        $contact = $this->vinContact();
        $contact->emails = [
            ['EmailId' => 1, 'EmailAddress' => 'jaime.new@gmail.com', 'EmailType' => 'primary'],
        ];
        $contact->phones = [
            ['PhoneId' => 1, 'Number' => '2025550111', 'PhoneType' => 'Cell'],
        ];

        $payload = $contact->buildUpdatePayload(19236, 1543430);

        $this->assertSame(
            'jaime.new@gmail.com',
            $payload['ContactInformation']['Emails'][0]['EmailAddress']
        );
        $this->assertSame('2025550111', $payload['ContactInformation']['Phones'][0]['Number']);
    }

    public function testRejectionIsRecordedAsANoteOnTheLeadInsteadOfThrowing(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        $reason = ContactRejectionService::recordForPeople($lead->people, $this->vinSolutionRejection());

        $this->assertStringContainsString('jaimefrc85@hotmail.com is not valid', $reason);

        $note = $lead->systemNotes?->messages()->latest('messages.id')->first();
        $this->assertNotNull($note, 'the rejection has to land where the dealer can see it');
        $this->assertStringContainsString('jaimefrc85@hotmail.com is not valid', $note->message['content']);
        $this->assertTrue($note->tags()->where('name', 'crm-sync-rejected')->exists());
        $this->assertSame(0, $note->is_public, 'internal sync failure — the customer must never see it');
    }

    public function testUnauthorizedContactIsRecordedAsANoteInsteadOfThrowing(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        $reason = ContactRejectionService::recordForLead($lead, $this->unauthorizedContact());

        $this->assertSame('User not authorized for Customer', $reason);

        $note = $lead->systemNotes?->messages()->latest('messages.id')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('did not authorize access', $note->message['content']);
        $this->assertSame(0, $note->is_public);
    }

    public function testDataRejectionsAndUnauthorizedContactsAreTheOnlySwallowedErrors(): void
    {
        $this->assertTrue(ContactRejectionService::isRecordRejection($this->vinSolutionRejection()));
        $this->assertTrue(ContactRejectionService::isRecordRejection($this->unauthorizedContact()));

        $this->assertFalse(
            ContactRejectionService::isRecordRejection(
                $this->vinSolutionRejection(status: 401, body: 'Invalid or expired access token')
            ),
            'a real credential failure is a system fault and must still surface'
        );
        $this->assertFalse(
            ContactRejectionService::isRecordRejection($this->vinSolutionRejection(status: 429)),
            'rate limiting must still surface'
        );
    }

    private function unauthorizedContact(): ClientException
    {
        return new ClientException(
            'Client error',
            new Request('GET', '/gateway/v1/contact/1438916379?dealerId=15595&userId=1330641'),
            new Response(401, [], '"User not authorized for Customer"')
        );
    }

    private function vinSolutionRejection(int $status = 400, ?string $body = null): ClientException
    {
        $body ??= json_encode([
            'ClassName' => 'System.ArgumentException',
            'Message' => 'jaimefrc85@hotmail.com is not valid.  Email message rejected by the verifier.',
        ]);

        return new ClientException(
            'Client error',
            new Request('PUT', '/gateway/v1/contact/987806390'),
            new Response($status, [], $body)
        );
    }

    private function vinContact(): Contact
    {
        return new Contact([
            'ContactId' => 987806390,
            'ContactInformation' => [
                'ContactId' => 987806390,
                'DealerId' => 19236,
                'FirstName' => 'Jaime',
                'LastName' => 'Rod',
                'Emails' => [
                    ['EmailId' => 1, 'EmailAddress' => 'jfrc985@gmail.com', 'EmailType' => 'Primary'],
                    ['EmailId' => 2, 'EmailAddress' => 'jaimefrc85@hotmail.com', 'EmailType' => 'Alternate'],
                ],
                'Phones' => [
                    ['PhoneId' => 1, 'Number' => '7865550100', 'PhoneType' => 'Cell'],
                ],
            ],
        ]);
    }
}
