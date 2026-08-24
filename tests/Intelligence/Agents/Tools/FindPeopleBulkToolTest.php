<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindPeopleBulkTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class FindPeopleBulkToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private Apps $currentApp;
    private Companies $currentCompany;
    private Users $actingUser;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->actingUser = static::$cachedUser;
        $this->currentCompany = $this->actingUser->getCurrentCompany();
        $this->tag = 'Bulk' . uniqid();
    }

    public function test_resolves_a_whole_list_in_one_call_preserving_order(): void
    {
        $jorgelina = $this->makePerson('Jorgelina' . $this->tag, 'Duran' . $this->tag);
        $sandra = $this->makePerson('Sandra' . $this->tag, 'Pichardo' . $this->tag);

        $result = $this->tool()->__invoke(
            names: sprintf(
                'Jorgelina%1$s Duran%1$s, Sandra%1$s Pichardo%1$s, Noexiste%1$s Persona%1$s',
                $this->tag,
            ),
        );

        $this->assertSame(3, $result['searched']);
        $this->assertSame(2, $result['matched']);
        $this->assertCount(3, $result['results']);

        $this->assertTrue($result['results'][0]['found']);
        $this->assertSame((int) $jorgelina->getId(), $result['results'][0]['matches'][0]['person_id']);

        $this->assertTrue($result['results'][1]['found']);
        $this->assertSame((int) $sandra->getId(), $result['results'][1]['matches'][0]['person_id']);

        $this->assertFalse($result['results'][2]['found']);
        $this->assertSame([], $result['results'][2]['matches']);
        $this->assertContains('Noexiste' . $this->tag . ' Persona' . $this->tag, $result['not_found']);
    }

    /**
     * The CSV that triggered this tool carried accents and second surnames the CRM row does not
     * ("Madelin C. Álvarez del Jesús" vs a stored "Madelin Alvarez"), so matching folds accents and
     * scores on shared tokens rather than requiring the strings to be equal.
     */
    public function test_matches_across_accents_extra_surnames_and_word_order(): void
    {
        $person = $this->makePerson('Madelin' . $this->tag, 'Alvarez' . $this->tag);

        $result = $this->tool()->__invoke(
            names: sprintf('Madelin%1$s C. Álvarez%1$s del Jesús', $this->tag),
        );

        $this->assertTrue($result['results'][0]['found']);
        $this->assertSame((int) $person->getId(), $result['results'][0]['matches'][0]['person_id']);
    }

    public function test_a_single_shared_first_name_is_not_a_match(): void
    {
        $this->makePerson('Sandra' . $this->tag, 'Pichardo' . $this->tag);

        $result = $this->tool()->__invoke(names: 'Sandra' . $this->tag . ' Rodriguez' . $this->tag);

        $this->assertFalse($result['results'][0]['found']);
    }

    public function test_returns_contact_details_for_a_match(): void
    {
        $person = $this->makePerson('Camila' . $this->tag, 'Fermin' . $this->tag);
        $email = 'camila-' . uniqid() . '@x.test';
        $person->addEmail($email, 0, 0);

        $result = $this->tool()->__invoke(names: 'Camila' . $this->tag . ' Fermin' . $this->tag);

        $match = $result['results'][0]['matches'][0];
        $this->assertSame((int) $person->getId(), $match['person_id']);
        $this->assertSame($email, $match['email']);
        $this->assertSame(2, $match['matched_tokens']);
    }

    public function test_does_not_leak_people_from_another_company(): void
    {
        $otherCompany = Companies::factory()->create();
        $foreign = People::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($otherCompany->getId())
            ->withUserId($this->actingUser->getId())
            ->create(['firstname' => 'Foreign' . $this->tag, 'lastname' => 'Person' . $this->tag]);

        $result = $this->tool()->__invoke(names: 'Foreign' . $this->tag . ' Person' . $this->tag);

        $this->assertFalse($result['results'][0]['found']);
        $this->assertNotContains(
            (int) $foreign->getId(),
            array_column($result['results'][0]['matches'], 'person_id'),
        );
    }

    public function test_duplicate_names_collapse_to_one_lookup(): void
    {
        $this->makePerson('Repeated' . $this->tag, 'Contact' . $this->tag);

        $result = $this->tool()->__invoke(
            names: sprintf('Repeated%1$s Contact%1$s, repeated%1$s contact%1$s', $this->tag),
        );

        $this->assertSame(1, $result['searched']);
    }

    public function test_blank_input_returns_an_actionable_error(): void
    {
        $result = $this->tool()->__invoke(names: '  ,  , ');

        $this->assertSame(0, $result['searched']);
        $this->assertArrayHasKey('error', $result);
    }

    private function tool(): FindPeopleBulkTool
    {
        return new FindPeopleBulkTool()
            ->withContext($this->currentApp, $this->currentCompany, $this->actingUser);
    }

    private function makePerson(string $first, string $last): People
    {
        return People::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->currentCompany->getId())
            ->withUserId($this->actingUser->getId())
            ->create(['firstname' => $first, 'lastname' => $last]);
    }
}
