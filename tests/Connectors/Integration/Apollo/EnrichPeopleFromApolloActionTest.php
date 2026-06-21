<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Apollo;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Apollo\Actions\EnrichPeopleFromApolloAction;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Tests\TestCase;

/**
 * Exercises the Apollo write-back deterministically: feed a canned `person`
 * payload through applyEnrichmentData() (no live Apollo call) and assert it
 * lands on the People record — current + past roles, contacts, job custom fields.
 */
final class EnrichPeopleFromApolloActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    public function test_applies_apollo_payload_to_people_record(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        $suffix = uniqid();
        $payload = [
            'first_name' => 'Maria',
            'last_name' => 'Perez',
            'headline' => 'CEO at Intras',
            'title' => 'Chief Executive Officer',
            'email' => "maria+{$suffix}@intras.test",
            'linkedin_url' => "https://linkedin.com/in/maria-{$suffix}",
            'phone_numbers' => [['sanitized_number' => '+18095550100']],
            'organization' => ['name' => "Intras Current {$suffix}"],
            'employment_history' => [
                [
                    'organization_name' => "Intras Current {$suffix}",
                    'raw_address' => null,
                    'title' => 'CEO',
                    'current' => 1,
                    'start_date' => '2020-01-01',
                    'end_date' => null,
                ],
                [
                    'organization_name' => "Old Corp {$suffix}",
                    'raw_address' => null,
                    'title' => 'Analyst',
                    'current' => 0,
                    'start_date' => '2010-01-01',
                    'end_date' => '2019-12-31',
                ],
            ],
        ];

        new EnrichPeopleFromApolloAction($people, $app)->applyEnrichmentData($payload);

        $this->assertSame('Chief Executive Officer', $people->get('title'), 'Current job title is stored as a custom field.');
        $this->assertSame('CEO at Intras', $people->get('headline'));

        $linkedinTypeId = ContactType::getByName('LinkedIn')->getId();
        $contacts = $people->contacts()->get();
        $this->assertTrue(
            $contacts->contains(fn ($c) => (int) $c->contacts_types_id === ContactTypeEnum::EMAIL->value && $c->value === "maria+{$suffix}@intras.test"),
            'Apollo email is attached as an EMAIL contact.',
        );
        $this->assertTrue(
            $contacts->contains(fn ($c) => (int) $c->contacts_types_id === $linkedinTypeId && $c->value === "https://linkedin.com/in/maria-{$suffix}"),
            'Apollo LinkedIn URL is attached as a LinkedIn contact.',
        );

        $history = PeopleEmploymentHistory::where('peoples_id', $people->getId())->get();
        $this->assertCount(2, $history, 'Current and past roles are both recorded.');

        $current = $history->firstWhere('status', 1);
        $this->assertNotNull($current);
        $this->assertSame('CEO', $current->position);

        $past = $history->firstWhere('status', 0);
        $this->assertNotNull($past);
        $this->assertSame('Analyst', $past->position);
        $this->assertSame('2019-12-31', (string) $past->end_date);
    }
}
