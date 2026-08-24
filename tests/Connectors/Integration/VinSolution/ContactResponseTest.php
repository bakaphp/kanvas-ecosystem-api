<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution;

use Kanvas\Connectors\VinSolution\Exceptions\ContactNotFoundException;
use Kanvas\Connectors\VinSolution\Leads\Contact;
use Tests\TestCase;

final class ContactResponseTest extends TestCase
{
    public function testEmptyResponseThrowsContactNotFoundInsteadOfUndefinedArrayKey(): void
    {
        $this->expectException(ContactNotFoundException::class);

        Contact::fromApiResponse([], 8675309);
    }

    public function testResponseWithoutContactIdThrowsContactNotFound(): void
    {
        $this->expectException(ContactNotFoundException::class);

        Contact::fromApiResponse(['Message' => 'Contact not available for this dealer'], 8675309);
    }

    public function testListResponseBuildsTheContact(): void
    {
        $contact = Contact::fromApiResponse(
            [
                [
                    'ContactId' => 8675309,
                    'ContactInformation' => [
                        'FirstName' => 'Jaime',
                        'Emails' => [['EmailId' => 1, 'EmailAddress' => 'jaime@gmail.com']],
                        'Phones' => [['PhoneId' => 1, 'Number' => '2025550111']],
                    ],
                ],
            ],
            8675309
        );

        $this->assertSame(8675309, $contact->id);
        $this->assertSame('Jaime', $contact->information['FirstName']);
        $this->assertSame('jaime@gmail.com', $contact->emails[0]['EmailAddress']);
    }

    public function testBareObjectResponseBuildsTheContact(): void
    {
        $contact = Contact::fromApiResponse(
            [
                'ContactId' => 8675309,
                'ContactInformation' => ['FirstName' => 'Jaime'],
            ],
            8675309
        );

        $this->assertSame(8675309, $contact->id);
        $this->assertSame('Jaime', $contact->information['FirstName']);
    }
}
