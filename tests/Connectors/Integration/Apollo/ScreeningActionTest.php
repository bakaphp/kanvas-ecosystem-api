<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Apollo;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Apollo\Actions\ScreeningAction;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

/**
 * Covers the selector logic that decides which /people/match payloads we send.
 * The regression it guards: Apollo resolves to ONE record per call (keyed by the
 * email / LinkedIn URL), so a person whose first email hits a sparse Apollo
 * record while a richer one exists under another email must still be tried under
 * every email — not just getEmails()->first().
 */
final class ScreeningActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    public function test_builds_a_match_candidate_per_email_with_linkedin_first(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        $suffix = uniqid();
        $officeEmail = "office-{$suffix}@alpa.test";
        $personalEmail = "personal-{$suffix}@carol.test";
        $linkedin = "https://www.linkedin.com/in/person-{$suffix}/";

        $people->addEmail($officeEmail, 0, 0);
        $this->addContact($people, ContactTypeEnum::SECONDARY_EMAIL->value, $personalEmail);
        $this->addContact($people, ContactType::getByName('LinkedIn')->getId(), $linkedin);

        $candidates = new ScreeningAction($people, $app)->buildMatchCandidates();

        // LinkedIn is the highest-confidence selector → first candidate.
        $this->assertSame($linkedin, $candidates[0]['linkedin_url'] ?? null);
        $this->assertArrayNotHasKey('email', $candidates[0]);

        // Every email becomes its own candidate (so a sparse first email can't hide a rich one).
        $emails = array_values(array_filter(array_map(fn ($c) => $c['email'] ?? null, $candidates)));
        $this->assertContains($officeEmail, $emails);
        $this->assertContains($personalEmail, $emails);

        // Identity fields ride on every candidate.
        foreach ($candidates as $candidate) {
            $this->assertSame($people->firstname, $candidate['first_name']);
            $this->assertSame($people->lastname, $candidate['last_name']);
            $this->assertSame($people->getName(), $candidate['name']);
        }
    }

    public function test_falls_back_to_a_name_only_candidate_when_no_email_or_linkedin(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        $people->contacts()->delete();

        $candidates = new ScreeningAction($people, $app)->buildMatchCandidates();

        $this->assertCount(1, $candidates);
        $this->assertArrayNotHasKey('email', $candidates[0]);
        $this->assertArrayNotHasKey('linkedin_url', $candidates[0]);
        $this->assertSame($people->getName(), $candidates[0]['name']);
    }

    private function addContact(People $people, int $typeId, string $value): void
    {
        Contact::create([
            'peoples_id' => $people->getId(),
            'contacts_types_id' => $typeId,
            'value' => $value,
            'weight' => 0,
            'is_opt_out' => 0,
        ]);
    }
}
