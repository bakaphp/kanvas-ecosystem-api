<?php

declare(strict_types=1);

namespace Tests\GraphQL\Event;

class EventPassCodeTest extends ResourceBookingBase
{
    public function testIssueEventCode(): void
    {
        $bookingData = $this->getBasicBookingData();
        $booking = $this->createBooking($bookingData);

        $response = $this->graphQL('
            mutation issueEventCode($event_id: ID!) {
                issueEventCode(event_id: $event_id) {
                    success
                    code
                    message
                    pass {
                        id
                        event_id
                        participant_id
                    }
                }
            }
        ', [
            'event_id' => $booking['event']['id'],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'));
        $result = $response->json('data.issueEventCode');

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['code']);
        $this->assertNull($result['pass']['participant_id']);
        $this->assertEquals($booking['event']['id'], $result['pass']['event_id']);
    }

    public function testIssueParticipantCode(): void
    {
        $bookingData = $this->getBasicBookingData();
        $booking = $this->createBooking($bookingData);

        // Get first participant
        $eventVersion = $this->graphQL('
            query {
                eventVersions(where: { column: ID, operator: EQ, value: "' . $booking['id'] . '" }) {
                    data {
                        id
                         participants(first: 10) {
                            data {
                                id
                                ticket_price
                                discount
                                invoice_date
                                metadata
                                created_at
                                updated_at
                                participant {
                                    id
                                    people {
                                        name
                                        firstname
                                        middlename
                                        lastname
                                    }
                                }
                            }
                        }
                    }
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ])->json('data.eventVersions.data.0');

        $participantId = $eventVersion['participants']['data'][0]['participant']['id'];

        $response = $this->graphQL('
            mutation issueParticipantCode($participant_id: ID!, $event_version_id: ID!) {
                issueParticipantCode(
                    participant_id: $participant_id,
                    event_version_id: $event_version_id,
                ) {
                    success
                    code
                    message
                    pass {
                        id
                        participant_id
                    }
                    participant {
                        id
                    }
                }
            }
        ', [
            'participant_id' => $participantId,
            'event_version_id' => $booking['id'],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'));
        $result = $response->json('data.issueParticipantCode');

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['code']);
        $this->assertEquals($participantId, $result['pass']['participant_id']);
    }

    public function testCheckInWithPin(): void
    {
        $bookingData = $this->getBasicBookingData();
        $booking = $this->createBooking($bookingData);

        // Issue an event code
        $issueResponse = $this->graphQL('
            mutation issueEventCode($event_id: ID!) {
                issueEventCode(event_id: $event_id) {
                    code
                }
            }
        ', [
            'event_id' => $booking['event']['id'],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $code = $issueResponse->json('data.issueEventCode.code');
        // Check in with the code
        $response = $this->graphQL('
            mutation checkInWithPin($code: String!) {
                checkInWithPin(code: $code) {
                    success
                    message
                    is_event_level
                    checked_in_at
                    pass {
                        id
                    }
                }
            }
        ', [
            'code' => $code,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'));
        $result = $response->json('data.checkInWithPin');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['is_event_level']);
        $this->assertNotEmpty($result['checked_in_at']);
    }

    public function testCheckInWithExpiredPin(): void
    {
        $bookingData = $this->getBasicBookingData();
        $booking = $this->createBooking($bookingData);

        // Issue an expired code
        $issueResponse = $this->graphQL('
            mutation issueEventCode($event_id: ID!, $expiration_date: DateTime!) {
                issueEventCode(
                    event_id: $event_id,
                    expiration_date: $expiration_date
                ) {
                    code
                }
            }
        ', [
            'event_id' => $booking['event']['id'],
            'expiration_date' => now()->subDay()->toDateTimeString(),
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $code = $issueResponse->json('data.issueEventCode.code');

        // Try to check in with expired code
        $response = $this->graphQL('
            mutation checkInWithPin($code: String!) {
                checkInWithPin(code: $code) {
                    success
                }
            }
        ', [
            'code' => $code,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNotNull($response->json('errors'));
        $this->assertStringContainsString('expired', $response->json('errors.0.message'));
    }
}
