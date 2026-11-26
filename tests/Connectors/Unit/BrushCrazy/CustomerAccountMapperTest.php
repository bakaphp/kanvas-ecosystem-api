<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\CustomerAccountMapper;
use Tests\TestCase;

final class CustomerAccountMapperTest extends TestCase
{
    /** bcrypt of "secret-pass". */
    private const string HASH = '$2y$04$8XyjsVZWwSmI4owWERm4vuNx.k5knFyzw8JPTaHooadcJ5xiGBSLW';

    public function testCopiesTheBcryptHashAndTheBalancesTheSiteReads(): void
    {
        $mapped = CustomerAccountMapper::map($this->row());

        $this->assertSame('jane@example.com', $mapped['email']);
        $this->assertSame('Jane', $mapped['firstname']);
        $this->assertSame('Doe', $mapped['lastname']);
        $this->assertSame(self::HASH, $mapped['password_hash']);
        $this->assertSame('1900-03-01', $mapped['dob']);
        $this->assertSame('120', $mapped['custom_fields'][CustomerAccountMapper::REWARD_POINTS_FIELD]);
        $this->assertSame('35.00', $mapped['custom_fields'][CustomerAccountMapper::PAINT_MONEY_FIELD]);
        $this->assertSame('12.50', $mapped['custom_fields'][CustomerAccountMapper::GIFT_CARD_BALANCE_FIELD]);
        $this->assertSame(42, $mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_USER_ID->value]);
        $this->assertFalse($mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_NEEDS_PASSWORD->value]);
    }

    public function testRowThatCannotSignInIsSkipped(): void
    {
        $this->assertNull(CustomerAccountMapper::map($this->row(['email' => '  '])));
        $this->assertNull(CustomerAccountMapper::map($this->row(['deleted_at' => '2024-01-01 00:00:00'])));
    }

    public function testUnusableHashIsDroppedAndTheCustomerMustSetAPassword(): void
    {
        $mapped = CustomerAccountMapper::map($this->row(['password' => 'plain-text']));

        $this->assertNull($mapped['password_hash']);
        $this->assertTrue($mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_NEEDS_PASSWORD->value]);
    }

    /** BrushCrazy fills a random password on accounts it makes by itself; nobody knows it. */
    public function testAutomaticAccountMustSetAPasswordEvenWithAHash(): void
    {
        $mapped = CustomerAccountMapper::map($this->row(['registration_method' => 'Automatic: Gift Card Purchase']));

        $this->assertSame(self::HASH, $mapped['password_hash']);
        $this->assertTrue($mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_NEEDS_PASSWORD->value]);
        $this->assertTrue(CustomerAccountMapper::isAutomatic('In-Studio Kiosk'));
        $this->assertFalse(CustomerAccountMapper::isAutomatic('Website'));
    }

    public function testUnknownBirthMonthLeavesTheDateOfBirthAlone(): void
    {
        $this->assertNull(CustomerAccountMapper::map($this->row(['birthday' => null]))['dob']);
        $this->assertNull(CustomerAccountMapper::map($this->row(['birthday' => 13]))['dob']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function row(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => 42,
            'name' => 'Jane Doe',
            'display_name' => 'Jane',
            'email' => ' Jane@Example.com ',
            'phone_number' => '(719) 555-0100',
            'password' => self::HASH,
            'registration_method' => 'Website',
            'birthday' => 3,
            'points' => 120,
            'rewards' => '{"amount":3500,"currency":"USD"}',
            'card' => '{"amount":1250,"currency":"USD"}',
            'deleted_at' => null,
            'created_at' => '2020-01-01 00:00:00',
        ]);
    }
}
