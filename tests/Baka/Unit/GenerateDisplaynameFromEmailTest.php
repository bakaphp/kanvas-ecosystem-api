<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Random;
use Kanvas\Apps\Models\Apps;
use Tests\TestCaseUnit;

final class GenerateDisplaynameFromEmailTest extends TestCaseUnit
{
    /**
     * Test GenerateDisplaynameFromEmail.
     */
    public function testGenerateDisplaynameFromEmail(): void
    {
        $app = app(Apps::class);
        $random = new Random();

        // Test normal email
        $email = 'jonasbright@example.com';
        $displayName = $random->generateDisplayNameFromEmail($email, $app);
        $this->assertEquals('jonasbright', $displayName);

        // Test email with numbers
        $emailWithNumbers = 'jonas123bright@example.com';
        $displayNameWithNumbers = $random->generateDisplayNameFromEmail($emailWithNumbers, $app);
        $this->assertEquals('jonasbright', $displayNameWithNumbers);

        // Test email with special characters
        $emailWithSpecialChars = 'john.doe+test@example.com';
        $displayNameWithSpecialChars = $random->generateDisplayNameFromEmail($emailWithSpecialChars, $app);
        $this->assertEquals('johndoetest', $displayNameWithSpecialChars);

        // Test Apple private relay email
        $appleEmail = 'random@privaterelay.appleid.com';
        $displayNameApple = $random->generateDisplayNameFromEmail($appleEmail, $app);

        $this->assertNotEmpty($displayNameApple);
        $this->assertMatchesRegularExpression('/[a-z]+[a-z]+/', $displayNameApple);
    }
}
