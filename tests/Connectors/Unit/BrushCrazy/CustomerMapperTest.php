<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\CustomerMapper;
use Tests\TestCase;

final class CustomerMapperTest extends TestCase
{
    public function testSplitsTheNameAndKeepsTheFullOneAsDisplayName(): void
    {
        $mapped = CustomerMapper::map($this->row(['name' => '  Mary   Ann  Smith ']));

        $this->assertSame('Mary', $mapped['people']['firstname']);
        $this->assertSame('Ann Smith', $mapped['people']['lastname']);
        $this->assertSame('Mary Ann Smith', $mapped['people']['name']);
    }

    public function testBlankNameFallsBackToTheEmail(): void
    {
        $mapped = CustomerMapper::map($this->row(['name' => ' ']));

        $this->assertSame('jane@example.com', $mapped['people']['firstname']);
        $this->assertSame('jane@example.com', $mapped['people']['name']);
    }

    public function testSoftDeletedUserIsFlaggedNotDropped(): void
    {
        $this->assertTrue(CustomerMapper::map($this->row(['deleted_at' => '2025-01-01 00:00:00']))['people']['is_deleted']);
        $this->assertFalse(CustomerMapper::map($this->row())['people']['is_deleted']);
    }

    public function testAddressNeedsAStreet(): void
    {
        $this->assertNull(CustomerMapper::map($this->row(['street_number' => null, 'route' => ' ']))['address']);

        $address = CustomerMapper::map($this->row())['address'];

        $this->assertSame('123 Main St', $address['address']);
        $this->assertSame('Columbus', $address['city']);
        $this->assertSame('OH', $address['state']);
        $this->assertSame('43004', $address['zip']);
        $this->assertNull($address['address_2']);
    }

    /** `birthday` is a month at the source; anything outside 1-12 is not a birthday. */
    public function testBirthdayIsAMonth(): void
    {
        $key = CustomFieldEnum::BRUSHCRAZY_BIRTHDAY_MONTH->value;

        $this->assertSame(7, CustomerMapper::map($this->row(['birthday' => 7]))['custom_fields'][$key]);
        $this->assertNull(CustomerMapper::map($this->row(['birthday' => 0]))['custom_fields'][$key]);
        $this->assertNull(CustomerMapper::map($this->row(['birthday' => 31]))['custom_fields'][$key]);
    }

    public function testLoyaltyAndPreferencesComeFromTheJsonColumns(): void
    {
        $fields = CustomerMapper::map($this->row([
            'points' => 0,
            'rewards' => '{"paint_money":5}',
            'settings' => '{"promotionEmails":false}',
        ]))['custom_fields'];

        $this->assertSame(0, $fields[CustomFieldEnum::BRUSHCRAZY_POINTS->value]);
        $this->assertSame(['paint_money' => 5], $fields[CustomFieldEnum::BRUSHCRAZY_REWARDS->value]);
        $this->assertFalse($fields[CustomFieldEnum::BRUSHCRAZY_PROMOTION_EMAILS->value]);
        $this->assertSame(42, $fields[CustomFieldEnum::BRUSHCRAZY_USER_ID->value]);
    }

    /** Null rather than absent: the sync deletes the field, so a value cleared at the source goes. */
    public function testClearedSourceValuesAreNullSoTheSyncDeletesThem(): void
    {
        $fields = CustomerMapper::map($this->row([
            'rewards' => '[]',
            'settings' => null,
            'display_name' => ' ',
            'favorite_studio' => null,
            'last_studio' => null,
        ]))['custom_fields'];

        foreach ([
            CustomFieldEnum::BRUSHCRAZY_REWARDS,
            CustomFieldEnum::BRUSHCRAZY_PROMOTION_EMAILS,
            CustomFieldEnum::BRUSHCRAZY_DISPLAY_NAME,
            CustomFieldEnum::BRUSHCRAZY_FAVORITE_STUDIO_ID,
            CustomFieldEnum::BRUSHCRAZY_LAST_STUDIO_ID,
        ] as $field) {
            $this->assertArrayHasKey($field->value, $fields);
            $this->assertNull($fields[$field->value]);
        }
    }

    public function testSecretsNeverReachTheResult(): void
    {
        $encoded = json_encode(CustomerMapper::map($this->row()));

        $this->assertStringNotContainsString('bcrypt-hash', $encoded);
        $this->assertStringNotContainsString('api-token', $encoded);
    }

    public function testVipCardIsTheBalanceInCents(): void
    {
        $key = CustomFieldEnum::BRUSHCRAZY_VIP_CARD_CENTS->value;

        $this->assertSame(2550, CustomerMapper::map($this->row())['custom_fields'][$key]);
        $this->assertSame(0, CustomerMapper::map($this->row(['card' => '']))['custom_fields'][$key]);
        $this->assertNull(CustomerMapper::map($this->row(['card' => null]))['custom_fields'][$key]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function row(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => 42,
            'name' => 'Jane Doe',
            'display_name' => null,
            'email' => 'jane@example.com',
            'phone_number' => '(614) 555-0100',
            'birthday' => null,
            'street_number' => '123',
            'route' => 'Main St',
            'suite' => null,
            'locality' => 'Columbus',
            'administrative_area_level_1' => 'OH',
            'postal_code' => '43004',
            'points' => 10,
            'rewards' => '{}',
            'settings' => '{}',
            'card' => '{"amount":2550,"currency":"USD"}',
            'password' => 'bcrypt-hash',
            'api_token' => 'api-token',
            'favorite_studio' => 2,
            'last_studio' => 2,
            'created_at' => '2020-05-01 10:00:00',
            'deleted_at' => null,
        ]);
    }
}
