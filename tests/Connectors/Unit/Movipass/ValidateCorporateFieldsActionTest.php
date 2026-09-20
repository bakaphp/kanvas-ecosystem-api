<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Movipass;

use Kanvas\Connectors\Movipass\Actions\ValidateCorporateFieldsAction;
use Tests\TestCase;

final class ValidateCorporateFieldsActionTest extends TestCase
{
    public function testAcceptsNineAndElevenDigitRncWithSeparators(): void
    {
        $this->assertNull(new ValidateCorporateFieldsAction(['rnc' => '131123456'])->execute());
        $this->assertNull(new ValidateCorporateFieldsAction(['rnc' => '001-1234567-8'])->execute());
    }

    public function testRejectsAnRncOfTheWrongLength(): void
    {
        $this->assertSame(
            'RNC must be 9 or 11 digits',
            new ValidateCorporateFieldsAction(['rnc' => '1234567'])->execute(),
        );
    }

    public function testRejectsAMalformedContactEmail(): void
    {
        $this->assertSame(
            'Contact email is not a valid email address',
            new ValidateCorporateFieldsAction(['contact_email' => 'not-an-email'])->execute(),
        );
    }

    /**
     * Presence is receiver configuration (`Field::requiredFor()`), so a receiver that does not
     * collect an RNC — a parking company, a fleet — must not be blocked by the shape rules.
     */
    public function testKeysThatAreAbsentAreNotChecked(): void
    {
        $this->assertNull(new ValidateCorporateFieldsAction([])->execute());
        $this->assertNull(new ValidateCorporateFieldsAction(['plate' => 'A123456', 'legal_name' => ''])->execute());
    }
}
