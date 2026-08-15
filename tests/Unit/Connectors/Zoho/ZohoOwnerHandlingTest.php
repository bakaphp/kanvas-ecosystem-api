<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Zoho;

use Kanvas\Connectors\Zoho\ZohoService;
use PHPUnit\Framework\TestCase;

/**
 * Covers the owner-handling behind Sentry KANVAS-ECOSYSTEM-2R8 / 5RT. We can't pre-validate the
 * Owner against Zoho's users API (tenant token lacks the users scope), so createAgent sends the
 * best owner candidate and retries without Owner when Zoho rejects that field.
 *
 *  - pickFirstOwnerId: which candidate goes out as Owner (owner link, then user link, then default).
 *  - isOwnerFieldRejection: did Zoho's INVALID_DATA response blame the Owner field (→ retry w/o it).
 */
final class ZohoOwnerHandlingTest extends TestCase
{
    public function testReturnsFirstNonEmptyCandidate(): void
    {
        $this->assertSame(200, ZohoService::pickFirstOwnerId(200, 100, 50));
    }

    public function testSkipsEmptyCandidatesAndUsesNextOne(): void
    {
        $this->assertSame(300, ZohoService::pickFirstOwnerId(null, '', 0, 300));
    }

    public function testFallsBackFromOwnerLinkToUserLinkToDefault(): void
    {
        // owner_linked_source_id empty, users_linked_source_id empty → company default owner.
        $this->assertSame(777, ZohoService::pickFirstOwnerId(null, null, 777));
    }

    public function testReturnsNullWhenAllCandidatesAreEmpty(): void
    {
        $this->assertNull(ZohoService::pickFirstOwnerId(null, '', 0));
    }

    public function testStringIdIsCastToInt(): void
    {
        $this->assertSame(200, ZohoService::pickFirstOwnerId('200'));
    }

    public function testIsOwnerFieldRejectionTrueWhenZohoBlamesOwner(): void
    {
        $body = [
            'data' => [
                [
                    'code' => 'INVALID_DATA',
                    'details' => ['api_name' => 'Owner', 'json_path' => '$.Owner'],
                    'status' => 'error',
                ],
            ],
        ];

        $this->assertTrue(ZohoService::isOwnerFieldRejection($body));
    }

    public function testIsOwnerFieldRejectionFalseForADifferentField(): void
    {
        $body = [
            'data' => [
                [
                    'code' => 'INVALID_DATA',
                    'details' => ['api_name' => 'Member_Number'],
                    'status' => 'error',
                ],
            ],
        ];

        $this->assertFalse(ZohoService::isOwnerFieldRejection($body));
    }

    public function testIsOwnerFieldRejectionFalseForNullOrEmptyBody(): void
    {
        $this->assertFalse(ZohoService::isOwnerFieldRejection(null));
        $this->assertFalse(ZohoService::isOwnerFieldRejection([]));
    }
}
