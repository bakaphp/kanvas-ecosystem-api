<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Unit;

use Tests\TestCaseUnit;
use Baka\Support\Random;

final class GenerateDisplaynameFromEmailTest extends TestCaseUnit
{
    /**
     * Test GenerateDisplaynameFromEmail.
     *
     * @return void
     */
    public function testGenerateDisplaynameFromEmail(): void
    {
        $random = new Random();

        // Test normal email
        $email = 'jonasbright@example.com';
        $displayName = $random->generateDisplayNameFromEmail($email);
        $this->assertEquals('jonasbright', $displayName);

        // Test email with numbers
        $emailWithNumbers = 'jonas123bright@example.com';
        $displayNameWithNumbers = $random->generateDisplayNameFromEmail($emailWithNumbers);
        $this->assertEquals('jonasbright', $displayNameWithNumbers);

        // Test email with special characters
        $emailWithSpecialChars = 'john.doe+test@example.com';
        $displayNameWithSpecialChars = $random->generateDisplayNameFromEmail($emailWithSpecialChars);
        $this->assertEquals('doe+test', $displayNameWithSpecialChars);

        // Test Apple private relay email
        $appleEmail = 'random@privaterelay.appleid.com';
        $displayNameApple = $random->generateDisplayNameFromEmail($appleEmail);
        $this->assertNotEmpty($displayNameApple);
        $this->assertMatchesRegularExpression('/[a-z]+[a-z]+/', $displayNameApple);
    }
}
