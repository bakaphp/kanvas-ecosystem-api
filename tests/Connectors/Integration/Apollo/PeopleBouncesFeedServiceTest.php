<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Apollo;

use Baka\Contracts\CompanyInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Apollo\Services\PeopleBouncesFeedService;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

final class PeopleBouncesFeedServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    public function test_lists_permanent_failures_and_excludes_soft_bounces_by_default(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $hardPerson = $this->makePerson($app, $company, 'Hardbounceuniq');
        $hardEmail = 'hard-' . uniqid() . '@x.test';
        $this->addBadEmail($hardPerson, $hardEmail, ContactValidationStatusEnum::HARD_BOUNCE);

        $softPerson = $this->makePerson($app, $company, 'Softbounceuniq');
        $softEmail = 'soft-' . uniqid() . '@x.test';
        $this->addBadEmail($softPerson, $softEmail, ContactValidationStatusEnum::SOFT_BOUNCE);

        $rows = new PeopleBouncesFeedService($app, $company)->rows();

        $mine = $this->onlyMine($rows);
        $this->assertCount(1, $mine);
        $this->assertSame($hardEmail, $mine[0]['email']);
        $this->assertSame('hard_bounce', $mine[0]['status']);
    }

    public function test_include_soft_bounce_widens_the_set(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $softPerson = $this->makePerson($app, $company, 'Softincludeuniq');
        $this->addBadEmail($softPerson, 'soft2-' . uniqid() . '@x.test', ContactValidationStatusEnum::SOFT_BOUNCE);

        $default = $this->onlyMine(new PeopleBouncesFeedService($app, $company)->rows());
        $widened = $this->onlyMine(new PeopleBouncesFeedService($app, $company)->rows(includeSoftBounce: true));

        $this->assertCount(0, $default);
        $this->assertCount(1, $widened);
        $this->assertSame('soft_bounce', $widened[0]['status']);
    }

    public function test_granularity_switches_between_per_email_and_per_person(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $person = $this->makePerson($app, $company, 'Twobademailuniq');
        $this->addBadEmail($person, 'a-' . uniqid() . '@x.test', ContactValidationStatusEnum::HARD_BOUNCE);
        $this->addBadEmail($person, 'b-' . uniqid() . '@x.test', ContactValidationStatusEnum::INVALID);

        $perEmail = $this->onlyMine(new PeopleBouncesFeedService($app, $company)->rows(granularity: 'per_email'));
        $perPerson = $this->onlyMine(new PeopleBouncesFeedService($app, $company)->rows(granularity: 'per_person'));

        $this->assertCount(2, $perEmail);
        $this->assertCount(1, $perPerson);
        // per_person keeps the most severe status (invalid ≥ hard_bounce)
        $this->assertSame('invalid', $perPerson[0]['status']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function onlyMine(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $r): bool => str_ends_with((string) $r['person'], 'uniq'),
        ));
    }

    private function makePerson(Apps $app, CompanyInterface $company, string $first): People
    {
        return People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create(['firstname' => $first, 'lastname' => '']);
    }

    private function addBadEmail(People $person, string $email, ContactValidationStatusEnum $status): void
    {
        $person->addEmail($email, 0, 0);
        $person->contacts()
            ->where('value', $email)
            ->firstOrFail()
            ->update(['validation_status' => $status->value]);
    }
}
