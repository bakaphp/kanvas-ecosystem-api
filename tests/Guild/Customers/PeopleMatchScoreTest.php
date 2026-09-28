<?php

declare(strict_types=1);

namespace Tests\Guild\Customers;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\PeopleMatchScore;
use Tests\TestCase;

final class PeopleMatchScoreTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testExactNameMatchScoresOne(): void
    {
        $people = $this->createPerson('Ramiro', 'Estrada');

        $score = PeopleMatchScore::for(
            $people,
            firstname: 'Ramiro',
            lastname: 'Estrada',
        );

        $this->assertSame(1.0, $score->value);
    }

    public function testCompletelyDifferentNameScoresZero(): void
    {
        $people = $this->createPerson('Ramiro', 'Estrada');

        $score = PeopleMatchScore::for(
            $people,
            firstname: 'Wolfgang',
            lastname: 'Zimmermann',
        );

        $this->assertSame(0.0, $score->value);
    }

    public function testNameMatchIsCaseInsensitive(): void
    {
        $people = $this->createPerson('Ramiro', 'Estrada');

        $score = PeopleMatchScore::for(
            $people,
            firstname: 'RAMIRO',
            lastname: 'estrada',
        );

        $this->assertSame(1.0, $score->value);
    }

    /**
     * The >= 80% similar_text threshold is the whole reason names are fuzzy at all:
     * CRMs disagree on spelling. Pin both sides of the boundary so a future tweak to
     * the constant fails loudly instead of silently widening or narrowing matching.
     */
    public function testNameJustAboveSimilarityThresholdMatches(): void
    {
        $people = $this->createPerson('Ramiro', 'Estrada');

        // "Ramiroo" vs "Ramiro" -> 2*6/13 = 92.3%
        $score = PeopleMatchScore::for($people, firstname: 'Ramiroo');

        $this->assertSame(1.0, $score->value);
    }

    public function testNameBelowSimilarityThresholdDoesNotMatch(): void
    {
        $people = $this->createPerson('Ramiro', 'Estrada');

        // "Ramon" vs "Ramiro" -> 2*4/11 = 72.7%
        $score = PeopleMatchScore::for($people, firstname: 'Ramon');

        $this->assertSame(0.0, $score->value);
    }

    public function testPhoneMatchesOnDigitsIgnoringFormatting(): void
    {
        $people = $this->createPerson('Ramiro', 'Estrada', phone: '2296466762');

        $score = PeopleMatchScore::for($people, phones: ['(229) 646-6762']);

        $this->assertSame(1.0, $score->value);
    }

    public function testDifferentPhoneDoesNotMatch(): void
    {
        $people = $this->createPerson('Ramiro', 'Estrada', phone: '2296466762');

        $score = PeopleMatchScore::for($people, phones: ['8095551234']);

        $this->assertSame(0.0, $score->value);
    }

    public function testEmailMatchesCaseInsensitively(): void
    {
        $people = $this->createPerson('Ramiro', 'Estrada', email: 'restrada@griffincdjr.com');

        $score = PeopleMatchScore::for($people, emails: ['RESTRADA@GriffinCDJR.com']);

        $this->assertSame(1.0, $score->value);
    }

    public function testPartialMatchIsRoundedToTwoDecimals(): void
    {
        $people = $this->createPerson('Ramiro', 'Estrada', email: 'restrada@griffincdjr.com');

        // 3 terms supplied, 2 match (firstname + email); lastname is wrong. 2/3 -> 0.67
        $score = PeopleMatchScore::for(
            $people,
            firstname: 'Ramiro',
            lastname: 'Zimmermann',
            emails: ['restrada@griffincdjr.com'],
        );

        $this->assertSame(0.67, $score->value);
    }

    /**
     * The floor is load-bearing, not a rounding artifact: the client renders
     * rank * 100, so 0.0 would draw "0% Match" on a candidate nothing was scored
     * against, which reads as "definitely wrong" rather than "unknown".
     */
    public function testNoSearchTermsReturnsFloorNotZero(): void
    {
        $people = $this->createPerson('Ramiro', 'Estrada');

        $score = PeopleMatchScore::for($people);

        $this->assertSame(0.1, $score->value);
        $this->assertTrue($score->isUnscored());
    }

    public function testCarriesMatchedAndTotalTermsSoCallersCanSeeWhyTheRatioIsWhatItIs(): void
    {
        $people = $this->createPerson('Ramiro', 'Estrada');

        $score = PeopleMatchScore::for(
            $people,
            firstname: 'Ramiro',
            lastname: 'Zimmermann',
        );

        $this->assertSame(0.5, $score->value);
        $this->assertSame(1, $score->matchedTerms);
        $this->assertSame(2, $score->totalTerms);
        $this->assertFalse($score->isUnscored());
    }

    public function testBlankAndNullTermsAreIgnoredRatherThanCountedAsMisses(): void
    {
        $people = $this->createPerson('Ramiro', 'Estrada');

        $score = PeopleMatchScore::for(
            $people,
            firstname: 'Ramiro',
            lastname: '',
            phones: [null, '  '],
            emails: [null],
        );

        $this->assertSame(1.0, $score->value);
    }

    private function createPerson(
        string $firstname,
        string $lastname,
        ?string $phone = null,
        ?string $email = null,
    ): People {
        $app = app(Apps::class);
        $user = auth()->user();

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create([
                'firstname' => $firstname,
                'lastname' => $lastname,
            ]);

        if ($phone !== null) {
            $people->addPhone($phone);
        }

        if ($email !== null) {
            $people->addEmail($email);
        }

        return $people->refresh();
    }
}
