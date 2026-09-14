<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit;

use Kanvas\Connectors\DriveCentric\Services\LeadUserService;
use Tests\TestCase;

final class DriveCentricSalespersonExtractionTest extends TestCase
{
    public function testExtractsCrmIdFromSalesperson1(): void
    {
        $deal = [
            'salesperson1' => [
                'firstName' => 'John',
                'identifiers' => [
                    ['type' => 'PartnerId', 'value' => '999'],
                    ['type' => 'CrmId', 'value' => '12345'],
                ],
            ],
        ];

        $this->assertSame('12345', LeadUserService::extractSalespersonCrmId($deal));
    }

    public function testExtractsCrmIdCaseInsensitively(): void
    {
        $deal = [
            'salesPerson' => [
                'identifiers' => [
                    ['type' => 'crmId', 'value' => 'abc-1'],
                ],
            ],
        ];

        $this->assertSame('abc-1', LeadUserService::extractSalespersonCrmId($deal));
    }

    public function testFallsBackToUserIdWhenThereAreNoIdentifiers(): void
    {
        $deal = [
            'user' => [
                'userId' => 778,
            ],
        ];

        $this->assertSame('778', LeadUserService::extractSalespersonCrmId($deal));
    }

    public function testReturnsNullWhenTheDealHasNoSalesperson(): void
    {
        $this->assertNull(LeadUserService::extractSalespersonCrmId([]));
        $this->assertNull(LeadUserService::extractSalespersonCrmId(['salesperson1' => null]));
        $this->assertNull(LeadUserService::extractSalespersonCrmId(['salesperson1' => []]));
    }

    public function testReturnsNullWhenTheCrmIdentifierIsEmpty(): void
    {
        $deal = [
            'salesperson1' => [
                'identifiers' => [
                    ['type' => 'CrmId', 'value' => ''],
                ],
            ],
        ];

        $this->assertNull(LeadUserService::extractSalespersonCrmId($deal));
    }

    public function testExtractsTheDealIdentifierFromTheIdentifiersArray(): void
    {
        $deal = [
            'identifiers' => [
                ['type' => 'PartnerId', 'value' => 'p-1'],
                ['type' => 'CrmId', 'value' => 'deal-77'],
            ],
        ];

        $this->assertSame('deal-77', LeadUserService::extractDealIdentifier($deal));
        $this->assertSame('p-1', LeadUserService::extractDealIdentifier($deal, 'PartnerId'));
    }

    /**
     * The download commands each carried their own copy of this scan without the `dealId` fallback,
     * so a deal identified that way was reported as having no CrmId and skipped — while the DTO
     * reading the same payload resolved it fine.
     */
    public function testFallsBackToABareDealIdForTheCrmIdentifier(): void
    {
        $this->assertSame('9182', LeadUserService::extractDealIdentifier(['dealId' => 9182]));
        $this->assertSame('9182', LeadUserService::extractDealIdentifier(['dealId' => 9182, 'identifiers' => []]));
        $this->assertNull(LeadUserService::extractDealIdentifier(['dealId' => 9182], 'PartnerId'));
    }

    public function testReturnsNullWhenTheDealCarriesNoIdentifierAtAll(): void
    {
        $this->assertNull(LeadUserService::extractDealIdentifier([]));
        $this->assertNull(LeadUserService::extractDealIdentifier(['identifiers' => [['type' => 'PartnerId', 'value' => 'p-2']]]));
    }
}
