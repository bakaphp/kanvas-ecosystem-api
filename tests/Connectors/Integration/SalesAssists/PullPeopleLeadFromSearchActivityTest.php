<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists;

use Kanvas\Connectors\SalesAssist\Activities\PullPeopleLeadFromSearchActivity;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class PullPeopleLeadFromSearchActivityTest extends TestCase
{
    private function buildCriteria(string $searchText): array
    {
        $activity = new ReflectionClass(PullPeopleLeadFromSearchActivity::class)->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($activity, 'buildEleadSearchCriteria');

        return $method->invoke($activity, $searchText);
    }

    public function testEmailRoutesToEmailAddress(): void
    {
        $this->assertSame(
            ['emailAddress' => 'jane.doe@example.com'],
            $this->buildCriteria('jane.doe@example.com')
        );
    }

    public function testPhoneRoutesToPhoneNumberAndStripsFormatting(): void
    {
        $this->assertSame(
            ['phoneNumber' => '8093505555'],
            $this->buildCriteria('(809) 350-5555')
        );
    }

    public function testShortDigitStringFallsBackToName(): void
    {
        // Fewer than 7 digits is not treated as a phone number.
        $this->assertSame(
            ['firstName' => '12345', 'lastName' => ''],
            $this->buildCriteria('12345')
        );
    }

    public function testFullNameSplitsIntoFirstAndLast(): void
    {
        $this->assertSame(
            ['firstName' => 'Jane', 'lastName' => 'Doe'],
            $this->buildCriteria('Jane Doe')
        );
    }

    public function testSingleNameLeavesLastNameEmpty(): void
    {
        $this->assertSame(
            ['firstName' => 'Jane', 'lastName' => ''],
            $this->buildCriteria('Jane')
        );
    }

    public function testExtraWhitespaceIsNormalized(): void
    {
        $this->assertSame(
            ['firstName' => 'Jane', 'lastName' => 'Doe'],
            $this->buildCriteria('  Jane   Doe  ')
        );
    }
}
