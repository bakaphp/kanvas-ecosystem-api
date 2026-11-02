<?php

declare(strict_types=1);

namespace Tests\Guild\Customers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Kanvas\Connectors\Intras\Support\IntrasPeopleTaxonomy;
use Kanvas\Guild\Customers\Classification\Actions\ApplyPeopleClassificationAction;
use Kanvas\Guild\Customers\Classification\Actions\ClassifyPeopleAction;
use Kanvas\Guild\Customers\Classification\Agents\PeopleClassificationAgent;
use Kanvas\Guild\Customers\Classification\DataTransferObject\PeopleTaxonomy;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationConfigurationEnum as ConfigurationEnum;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationFieldEnum as FieldEnum;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationSourceEnum as SourceEnum;
use Kanvas\Guild\Customers\Classification\Jobs\ClassifyPeopleJob;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\NervousSystem\Ledger\Models\Event;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * Serial because the settings are company settings in shared Redis. Titles are unique per test since
 * the classifier's cache is shared Redis too.
 */
#[Group('serial')]
final class PeopleClassificationTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'social', 'intelligence'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();

        $this->company->set(ConfigurationEnum::ENABLED->value, true);
        $this->company->set(ConfigurationEnum::TAXONOMY->value, IntrasPeopleTaxonomy::VALUE);
        $this->company->set(ConfigurationEnum::APPLY_TAGS->value, true);
    }

    protected function tearDown(): void
    {
        foreach (ConfigurationEnum::cases() as $key) {
            $this->company->del($key->value);
        }

        parent::tearDown();
    }

    public function test_a_confident_answer_is_applied_and_tagged_without_touching_other_tags(): void
    {
        $people = $this->peopleWithTitle();
        $people->addTags(
            ['Grupo VIP'],
            $this->kanvasApp,
            static::$cachedUser,
            $this->company,
        );
        $this->fakeAnswer('GERENTE', 'FINANZAS', 0.95);

        $result = new ClassifyPeopleAction($people)->execute();

        $this->assertSame('applied', $result['status']);
        $this->assertSame('GERENTE', $people->get(FieldEnum::LEVEL->value));
        $this->assertSame('FINANZAS', $people->get(FieldEnum::AREA->value));
        $this->assertSame(SourceEnum::AI->value, $people->get(FieldEnum::SOURCE->value));
        $this->assertEqualsCanonicalizing(['Grupo VIP', 'Nivel: Gerencia', 'Área: Finanzas'], $people->tags()->pluck('name')->all());
    }

    public function test_a_low_confidence_answer_waits_for_review_with_no_values_tags_or_sipgo_push(): void
    {
        $people = $this->peopleWithTitle();
        $this->fakeAnswer('GERENTE', 'FINANZAS', 0.5);

        $result = new ClassifyPeopleAction($people)->execute();

        $this->assertSame('pending_review', $result['status']);
        $this->assertNull($people->get(FieldEnum::LEVEL->value));
        $this->assertSame(0, $people->tags()->count());
        $this->assertSame('GERENTE', $people->get(FieldEnum::SUGGESTED->value)['level']);
        $this->assertSame([], ParticipantMapper::classificationChanges($people));
    }

    public function test_a_value_outside_the_taxonomy_is_dropped(): void
    {
        $people = $this->peopleWithTitle();
        $this->fakeAnswer('EMPERADOR', 'FINANZAS', 0.99);

        new ClassifyPeopleAction($people)->execute();

        $this->assertNull($people->get(FieldEnum::LEVEL->value));
        $this->assertSame('FINANZAS', $people->get(FieldEnum::AREA->value));
    }

    public function test_an_unknown_answer_on_both_axes_is_not_applied_however_confident(): void
    {
        $people = $this->peopleWithTitle();
        $this->fakeAnswer(PeopleClassificationAgent::UNKNOWN, PeopleClassificationAgent::UNKNOWN, 0.99);

        $this->assertSame('pending_review', new ClassifyPeopleAction($people)->execute()['status']);
    }

    public function test_an_unchanged_title_is_not_paid_for_again_and_a_new_title_is(): void
    {
        $people = $this->peopleWithTitle();
        $this->fakeAnswer('GERENTE', 'FINANZAS', 0.95);
        new ClassifyPeopleAction($people)->execute();

        $this->assertSame('unchanged', new ClassifyPeopleAction($people)->execute()['status']);

        $people->set('position', 'Director ' . Str::random(12));
        $this->fakeAnswer('DIRECTOR', 'FINANZAS', 0.95);

        $this->assertSame('applied', new ClassifyPeopleAction($people)->execute()['status']);
        $this->assertSame('DIRECTOR', $people->get(FieldEnum::LEVEL->value));
        $this->assertEqualsCanonicalizing(['Nivel: Dirección', 'Área: Finanzas'], $people->tags()->pluck('name')->all());
    }

    public function test_two_people_with_the_same_title_cost_one_model_call(): void
    {
        $title = 'Gerente ' . Str::random(12);
        $this->fakeAnswer('GERENTE', 'FINANZAS', 0.95);
        new ClassifyPeopleAction($this->peopleWithTitle($title))->execute();

        PeopleClassificationAgent::fake(fn () => $this->fail('The second person should come from the cache.'));
        $result = new ClassifyPeopleAction($this->peopleWithTitle(mb_strtoupper($title) . '  '))->execute();

        $this->assertSame('applied', $result['status']);
    }

    public function test_a_manual_choice_survives_a_forced_run(): void
    {
        $people = $this->peopleWithTitle();
        new ApplyPeopleClassificationAction(
            people: $people,
            level: 'DIRECTOR',
            area: 'RRHH',
            source: SourceEnum::MANUAL,
        )->execute();

        $this->assertSame('manual', new ClassifyPeopleAction($people, force: true)->execute()['status']);
        $this->assertSame('DIRECTOR', $people->get(FieldEnum::LEVEL->value));
    }

    public function test_a_person_with_no_title_is_skipped(): void
    {
        $people = $this->peopleWithTitle();
        $people->del('position');

        $this->assertSame('no_title', new ClassifyPeopleAction($people)->execute()['status']);
    }

    public function test_tags_are_optional(): void
    {
        $this->company->del(ConfigurationEnum::APPLY_TAGS->value);
        $people = $this->peopleWithTitle();
        $this->fakeAnswer('GERENTE', 'FINANZAS', 0.95);

        new ClassifyPeopleAction($people)->execute();

        $this->assertSame('GERENTE', $people->get(FieldEnum::LEVEL->value));
        $this->assertSame(0, $people->tags()->count());
    }

    public function test_dispatch_queues_only_when_enabled_and_the_title_changed(): void
    {
        Queue::fake();
        $people = $this->peopleWithTitle();

        ClassifyPeopleJob::dispatchIfEnabled($people);
        Queue::assertPushed(ClassifyPeopleJob::class, 1);

        $this->company->del(ConfigurationEnum::ENABLED->value);
        $other = $this->peopleWithTitle();
        ClassifyPeopleJob::dispatchIfEnabled($other);

        Queue::assertPushed(ClassifyPeopleJob::class, 1);
    }

    public function test_dispatch_restores_tags_a_form_save_wiped_without_a_model_call(): void
    {
        $people = $this->peopleWithTitle();
        $this->fakeAnswer('GERENTE', 'FINANZAS', 0.95);
        new ClassifyPeopleAction($people)->execute();

        $people->syncTags([]);
        $this->assertSame(0, $people->tags()->count());

        Queue::fake();
        ClassifyPeopleJob::dispatchIfEnabled($people);

        Queue::assertNothingPushed();
        $this->assertEqualsCanonicalizing(['Nivel: Gerencia', 'Área: Finanzas'], $people->tags()->pluck('name')->all());
    }

    public function test_the_prompt_carries_the_title_and_the_schema_only_allows_taxonomy_codes(): void
    {
        $title = 'Asistente del Director General ' . Str::random(12);
        $people = $this->peopleWithTitle($title);
        $this->fakeAnswer('ESPECIALISTA', 'DIRECCION', 0.9);

        new ClassifyPeopleAction($people)->execute();

        PeopleClassificationAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, $title));
    }

    public function test_the_mutation_records_a_manual_choice_and_rejects_a_code_outside_the_taxonomy(): void
    {
        $people = $this->peopleWithTitle();
        $mutation = 'mutation($id: ID!, $level: String) {
            setPeopleClassification(id: $id, level: $level) { id }
        }';

        $this->graphQL($mutation, ['id' => $people->getId(), 'level' => 'JEFATURA'])
            ->assertSuccessful()
            ->assertJsonMissingPath('errors');

        $fresh = People::getById($people->getId());
        $this->assertSame('JEFATURA', $fresh->get(FieldEnum::LEVEL->value));
        $this->assertSame(SourceEnum::MANUAL->value, $fresh->get(FieldEnum::SOURCE->value));

        $this->graphQL($mutation, ['id' => $people->getId(), 'level' => 'EMPERADOR'])
            ->assertJsonPath('errors.0.message', 'Unknown level: EMPERADOR.');
    }

    public function test_the_suggestion_mutation_returns_the_answer_without_touching_any_person(): void
    {
        $mutation = 'mutation($title: String!) {
            suggestPeopleClassification(title: $title) { level area confidence normalized_title }
        }';
        $this->fakeAnswer('GERENTE', 'FINANZAS', 0.5);

        $this->graphQL($mutation, ['title' => 'Gerente ' . Str::random(12)])
            ->assertSuccessful()
            ->assertJsonPath('data.suggestPeopleClassification.level', 'GERENTE')
            ->assertJsonPath('data.suggestPeopleClassification.confidence', 0.5);

        $this->graphQL($mutation, ['title' => '   '])
            ->assertJsonPath('errors.0.message', 'Variable "$title" of non-null type "String!" must not be null.');

        $this->company->del(ConfigurationEnum::ENABLED->value);

        $this->graphQL($mutation, ['title' => 'Gerente ' . Str::random(12)])
            ->assertJsonPath('errors.0.message', 'People classification is not enabled for this company.');
    }

    public function test_an_unusable_model_reply_is_neither_cached_nor_recorded_as_the_answer(): void
    {
        $people = $this->peopleWithTitle();
        PeopleClassificationAgent::fake([[]]);

        try {
            new ClassifyPeopleAction($people)->execute();
            $this->fail('Expected an unusable reply to fail so the job retries.');
        } catch (RuntimeException) {
        }

        $this->assertNull($people->get(FieldEnum::HASH->value));
        $this->assertNull($people->get(FieldEnum::SOURCE->value));

        $this->fakeAnswer('GERENTE', 'FINANZAS', 0.95);

        $this->assertSame('applied', new ClassifyPeopleAction($people)->execute()['status']);
    }

    public function test_a_manual_change_records_no_model_confidence(): void
    {
        $people = $this->peopleWithTitle();
        $this->fakeAnswer('GERENTE', 'FINANZAS', 0.95);
        new ClassifyPeopleAction($people)->execute();

        new ApplyPeopleClassificationAction(
            people: $people,
            level: 'DIRECTOR',
            area: 'FINANZAS',
            source: SourceEnum::MANUAL,
            user: static::$cachedUser,
        )->execute();

        $events = $this->classifiedEvents($people);

        $this->assertNull($events->first()->payload['confidence']);
        $this->assertEquals(0.95, $events->last()->payload['confidence']);
    }

    public function test_a_change_leaves_a_note_and_a_ledger_event_with_old_and_new_values(): void
    {
        $people = $this->peopleWithTitle();
        $this->fakeAnswer('GERENTE', 'FINANZAS', 0.95);
        new ClassifyPeopleAction($people)->execute();

        new ApplyPeopleClassificationAction(
            people: $people,
            level: 'DIRECTOR',
            area: 'FINANZAS',
            source: SourceEnum::MANUAL,
            user: static::$cachedUser,
        )->execute();

        $events = $this->classifiedEvents($people);
        $this->assertCount(2, $events);

        $latest = $events->first();
        $this->assertSame('DIRECTOR', $latest->payload['level']);
        $this->assertSame('GERENTE', $latest->payload['previous_level']);
        $this->assertSame(SourceEnum::MANUAL->value, $latest->payload['source']);
        $this->assertSame('User', $latest->actor_type);
        $this->assertSame('System', $events->last()->actor_type);

        $notes = $this->classificationNotes($people);
        $this->assertCount(2, $notes);
        $this->assertStringContainsString('level Dirección, was Gerencia', $notes->first());
    }

    public function test_no_change_leaves_no_new_record(): void
    {
        $people = $this->peopleWithTitle();
        $this->fakeAnswer('GERENTE', 'FINANZAS', 0.95);
        new ClassifyPeopleAction($people)->execute();

        $this->fakeAnswer('GERENTE', 'FINANZAS', 0.95);
        new ClassifyPeopleAction($people, force: true)->execute();

        $this->assertCount(1, $this->classifiedEvents($people));
        $this->assertCount(1, $this->classificationNotes($people));
    }

    public function test_force_asks_the_model_again_instead_of_reusing_the_cached_answer(): void
    {
        $people = $this->peopleWithTitle();
        $this->fakeAnswer('GERENTE', 'FINANZAS', 0.95);
        new ClassifyPeopleAction($people)->execute();

        $this->fakeAnswer('DIRECTOR', 'FINANZAS', 0.95);
        new ClassifyPeopleAction($people, force: true)->execute();

        $this->assertSame('DIRECTOR', $people->get(FieldEnum::LEVEL->value));
    }

    public function test_a_first_low_confidence_answer_records_nothing_but_clearing_a_value_does(): void
    {
        $people = $this->peopleWithTitle();
        $this->fakeAnswer('GERENTE', 'FINANZAS', 0.5);
        new ClassifyPeopleAction($people)->execute();

        $this->assertCount(0, $this->classifiedEvents($people));

        $this->fakeAnswer('GERENTE', 'FINANZAS', 0.95);
        new ClassifyPeopleAction($people, force: true)->execute();
        $people->set('position', 'Consultor ' . Str::random(12));
        $this->fakeAnswer('ESPECIALISTA', 'FINANZAS', 0.5);
        new ClassifyPeopleAction($people)->execute();

        $latest = $this->classifiedEvents($people)->first();
        $this->assertNull($latest->payload['level']);
        $this->assertSame('GERENTE', $latest->payload['previous_level']);
        $this->assertSame(SourceEnum::PENDING_REVIEW->value, $latest->payload['source']);
    }

    public function test_the_shipped_taxonomy_tags_never_contain_a_comma(): void
    {
        $taxonomy = PeopleTaxonomy::fromSetting(IntrasPeopleTaxonomy::VALUE);

        foreach ($taxonomy->allTags() as $tag) {
            $this->assertStringNotContainsString(',', $tag);
        }

        $this->assertCount(12, $taxonomy->allTags());
    }

    private function classifiedEvents(People $people): Collection
    {
        return Event::query()
            ->where('event_type', 'guild.people.classified')
            ->where('source_entity_type', People::class)
            ->where('source_entity_id', (int) $people->getId())
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return SupportCollection<int, string>
     */
    private function classificationNotes(People $people): SupportCollection
    {
        return $people->refresh()->notes->messages()
            ->whereHas('tags', fn ($query) => $query->where('name', 'classification'))
            ->orderByDesc('messages.id')
            ->get()
            ->map(fn ($message) => (string) $message->message['content']);
    }

    private function peopleWithTitle(?string $title = null): People
    {
        $people = People::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        $people->set('position', $title ?? 'Gerente ' . Str::random(12));

        return $people;
    }

    private function fakeAnswer(string $level, string $area, float $confidence): void
    {
        PeopleClassificationAgent::fake([[
            'level' => $level,
            'area' => $area,
            'confidence' => $confidence,
            'normalized_title' => 'Gerente',
        ]]);
    }
}
