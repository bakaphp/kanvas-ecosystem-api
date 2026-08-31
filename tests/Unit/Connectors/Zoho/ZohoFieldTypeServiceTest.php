<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Zoho;

use Kanvas\Connectors\Zoho\Services\ZohoFieldTypeService;
use PHPUnit\Framework\TestCase;

/**
 * Covers the INVALID_DATA recovery behind the InvalidDataType Sentry issue: Zoho rejected a whole
 * lead because the form sent "$50,000" to a currency field. We coerce the fields Zoho names and
 * retry, dropping the ones we can't make fit instead of losing the lead.
 */
final class ZohoFieldTypeServiceTest extends TestCase
{
    private const REJECTION_BODY = [
        'data' => [
            [
                'code' => 'INVALID_DATA',
                'details' => [
                    'expected_data_type' => 'currency',
                    'api_name' => 'Amount_Requested',
                ],
                'message' => 'invalid data',
                'status' => 'error',
            ],
        ],
    ];

    public function testCurrencyStripsFormattingIntoANumber(): void
    {
        $this->assertSame(50000.0, ZohoFieldTypeService::cast('$50,000', 'currency'));
        $this->assertSame(1234.56, ZohoFieldTypeService::cast('$1,234.56 USD', 'double'));
        $this->assertSame(-50.0, ZohoFieldTypeService::cast('-$50', 'decimal'));
    }

    public function testIntegerTypesDropTheDecimals(): void
    {
        $this->assertSame(1234, ZohoFieldTypeService::cast('1,234.90', 'integer'));
        $this->assertSame(99999, ZohoFieldTypeService::cast('99999', 'bigint'));
    }

    public function testNumericCastReturnsNullWhenThereIsNoNumberToRecover(): void
    {
        $this->assertNull(ZohoFieldTypeService::cast('N/A', 'currency'));
        $this->assertNull(ZohoFieldTypeService::cast('', 'currency'));
        $this->assertNull(ZohoFieldTypeService::cast(null, 'currency'));
    }

    public function testBooleanCast(): void
    {
        $this->assertTrue(ZohoFieldTypeService::cast('yes', 'boolean'));
        $this->assertFalse(ZohoFieldTypeService::cast('false', 'boolean'));
        $this->assertNull(ZohoFieldTypeService::cast('maybe', 'boolean'));
    }

    public function testDateCastNormalizesAndRefusesToInventOne(): void
    {
        $this->assertSame('2026-08-28', ZohoFieldTypeService::cast('08/28/2026', 'date'));
        $this->assertNull(ZohoFieldTypeService::cast(null, 'date'));
        $this->assertNull(ZohoFieldTypeService::cast('not a date', 'date'));
    }

    public function testUnknownTypeIsNotCastable(): void
    {
        $this->assertFalse(ZohoFieldTypeService::canCast('picklist'));
        $this->assertNull(ZohoFieldTypeService::cast('Excellent (720+)', 'picklist'));
    }

    public function testInvalidTypeFieldsReadsApiNameAndExpectedType(): void
    {
        $this->assertSame(
            ['Amount_Requested' => 'currency'],
            ZohoFieldTypeService::invalidTypeFields(self::REJECTION_BODY)
        );
    }

    public function testInvalidTypeFieldsIgnoresOtherErrorCodes(): void
    {
        $body = [
            'data' => [
                [
                    'code' => 'MANDATORY_NOT_FOUND',
                    'details' => ['api_name' => 'Last_Name'],
                    'status' => 'error',
                ],
            ],
        ];

        $this->assertSame([], ZohoFieldTypeService::invalidTypeFields($body));
        $this->assertSame([], ZohoFieldTypeService::invalidTypeFields(null));
    }

    public function testCoercePayloadFixesOnlyTheRejectedField(): void
    {
        $payload = [
            'Last_Name' => 'D BARNES',
            'Amount_Requested' => '$50,000',
            'Annual_Revenue' => 360000,
        ];

        $this->assertSame(
            [
                'Last_Name' => 'D BARNES',
                'Amount_Requested' => 50000.0,
                'Annual_Revenue' => 360000,
            ],
            ZohoFieldTypeService::coercePayload($payload, self::REJECTION_BODY)
        );
    }

    public function testCoercePayloadMatchesTheFieldCaseInsensitively(): void
    {
        $coerced = ZohoFieldTypeService::coercePayload(['amount_requested' => '$50,000'], self::REJECTION_BODY);

        $this->assertSame(['amount_requested' => 50000.0], $coerced);
    }

    public function testCoercePayloadDropsAValueItCannotFix(): void
    {
        $coerced = ZohoFieldTypeService::coercePayload(
            ['Last_Name' => 'D BARNES', 'Amount_Requested' => 'to be defined'],
            self::REJECTION_BODY
        );

        $this->assertSame(['Last_Name' => 'D BARNES'], $coerced);
    }

    public function testCoercePayloadReturnsNullWhenARetryWouldSendTheSamePayload(): void
    {
        $this->assertNull(
            ZohoFieldTypeService::coercePayload(['Amount_Requested' => 50000.0], self::REJECTION_BODY)
        );

        $this->assertNull(
            ZohoFieldTypeService::coercePayload(['Amount_Requested' => '$50,000'], null)
        );

        $this->assertNull(
            ZohoFieldTypeService::coercePayload(['Last_Name' => 'D BARNES'], self::REJECTION_BODY)
        );
    }
}
