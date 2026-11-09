<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Leads\Contact;
use Kanvas\Connectors\VinSolution\Support\Address as VinAddress;
use Kanvas\Connectors\VinSolution\Workflow\PushLeadNotesActivity;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;

final class PushLeadNotesIdVerificationTest extends TestCase
{
    public function testIntellicheckResultUpdatesThePersonAndPushesIt(): void
    {
        $lead = $this->makeLead();
        $activity = $this->activity();

        $result = $activity->syncIdVerification($lead, $this->intellicheckMessage());

        $this->assertSame('115 Flint Hill Dr', $result['address1']);
        $this->assertSame([$lead->people->getId()], $activity->pushed);

        $people = $lead->people->fresh();
        $address = $people->getDefaultAddress();
        $this->assertSame('Jowsmilk', $people->firstname);
        $this->assertSame('Perez', $people->lastname);
        $this->assertSame('115 Flint Hill Dr', $address->address);
        $this->assertSame('Oxford', $address->city);
        $this->assertSame('GA', $address->state);
        $this->assertSame('30054-3928', $address->zip);
    }

    public function testWebFormAddressStillUpdatesAndNowPushes(): void
    {
        $lead = $this->makeLead();
        $activity = $this->activity();

        $result = $activity->syncIdVerification($lead, [
            'verb' => 'id-verification',
            'status' => 'opened',
            'data' => [
                'address' => [
                    'address' => '9 Old Rd',
                    'city' => 'Tampa',
                    'state' => 'FL',
                    'zipcode' => '33601',
                ],
            ],
        ]);

        $this->assertSame('9 Old Rd', $result['address']['address']);
        $this->assertSame([$lead->people->getId()], $activity->pushed);
        $this->assertSame('9 Old Rd', $lead->people->fresh()->getDefaultAddress()->address);
        $this->assertSame(1, (int) $lead->get('dont-run-id-verification-manual'));
    }

    public function testSubmittedMessageWithoutAScanDoesNothing(): void
    {
        $lead = $this->makeLead();
        $activity = $this->activity();

        $result = $activity->syncIdVerification($lead, [
            'verb' => 'id-verification',
            'status' => 'submitted',
            'data' => ['form' => []],
        ]);

        $this->assertNull($result);
        $this->assertSame([], $activity->pushed);
    }

    public function testPostalCodeIsSentAsDigitsOnly(): void
    {
        $lead = $this->makeLead();
        $this->activity()->syncIdVerification($lead, $this->intellicheckMessage());

        $payload = new VinAddress(0, $lead->people->fresh()->getDefaultAddress())->transform();

        $this->assertSame('300543928', $payload['PostalCode']);
        $this->assertSame('GA', $payload['State']);
        $this->assertSame('Oxford', $payload['City']);
    }

    private function intellicheckMessage(): array
    {
        return [
            'status' => 'submitted',
            'verb' => 'id-verification',
            'data' => [
                'form' => [
                    'public_data' => ['endpoint' => 'postback', 'version' => '3.14.1'],
                    'private_data' => [
                        'transaction_id' => '072d8584-7f30-4667-ab18-af98ce7df594',
                        'result' => [
                            'idcheck' => [
                                'success' => true,
                                'data' => [
                                    'processResult' => 'DocumentProcessOK',
                                    'firstName' => 'Jowsmilk',
                                    'middleName' => '',
                                    'lastName' => 'Perez',
                                    'address1' => '115 Flint Hill Dr',
                                    'address2' => '',
                                    'city' => 'Oxford',
                                    'state' => 'GA',
                                    'postalCode' => '30054-3928',
                                    'dateOfBirth' => '09/14/1980',
                                ],
                            ],
                            'facial' => ['success' => true, 'result' => true],
                        ],
                    ],
                ],
            ],
            'engagement_status' => 'submitted',
            'source' => 'webhook',
        ];
    }

    private function makeLead(): Lead
    {
        return Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();
    }

    /**
     * The real push resolves live VinSolutions credentials; here it only records who was pushed.
     */
    private function activity(): PushLeadNotesActivity
    {
        return new class (0, now()->toDateTimeString(), new StoredWorkflow(), []) extends PushLeadNotesActivity {
            public array $pushed = [];

            protected function pushPeople(People $people): Contact
            {
                $this->pushed[] = $people->getId();

                return new Contact(['ContactId' => 1]);
            }
        };
    }
}
