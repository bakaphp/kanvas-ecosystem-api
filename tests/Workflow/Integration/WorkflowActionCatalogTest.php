<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WordPress\Activities\PushMessageToWordPressActivity;
use Kanvas\Connectors\WordPress\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\ListWorkflowOptionsTool;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Services\WorkflowActionDiscoveryService;
use ReflectionMethod;
use Tests\TestCase;

final class WorkflowActionCatalogTest extends TestCase
{
    use DatabaseTransactions;

    // `ecosystem` matters as much as the other two: SystemModules lives there, and a connection left
    // off this list is not wrapped in a transaction at all — the rows this test seeds would then be
    // committed for good, accumulating in the shared database on every run.
    protected $connectionsToTransact = ['mysql', 'workflow', 'ecosystem'];

    public function testDiscoveryReturnsTheMetadataTheAttributeDeclares(): void
    {
        $entry = $this->discoveredEntryFor(PushMessageToWordPressActivity::class);

        $this->assertNotNull($entry, 'PushMessageToWordPressActivity was not discovered.');
        $this->assertSame('Push Message To WordPress', $entry['name']);
        $this->assertNotEmpty($entry['description']);
        $this->assertSame('wordpress', $entry['integration']);
        $this->assertContains(ConfigurationEnum::SITE_URL->value, $entry['requires_config']);
        $this->assertArrayHasKey('message_type_id', $entry['params']);
        $this->assertSame(['message_type_id'], $entry['required_params']);
    }

    public function testTheCatalogTellsTheAgentWhichParamsAreRequired(): void
    {
        $this->syncCatalog();

        $entry = $this->entryNamed(
            $this->listOptions('actions', 'wordpress')['actions'] ?? [],
            'Push Message To WordPress'
        );

        $this->assertNotNull($entry);
        $this->assertSame(['message_type_id'], $entry['required_params']);
    }

    public function testKindIsDerivedFromTheBaseClassRatherThanDeclared(): void
    {
        $byClass = [];

        foreach (new WorkflowActionDiscoveryService()->discover() as $entry) {
            $byClass[$entry['class']] = $entry['kind'];
        }

        $this->assertSame(
            ActionKindEnum::WORKFLOW->value,
            $byClass[PushMessageToWordPressActivity::class] ?? null,
            'A KanvasActivity must be catalogued as a workflow step.'
        );

        $receiverKinds = [];

        foreach ($byClass as $class => $kind) {
            if (is_subclass_of($class, ProcessWebhookJob::class)) {
                $receiverKinds[$class] = $kind;
            }
        }

        $this->assertNotEmpty($receiverKinds, 'Expected at least one ProcessWebhookJob in the catalog.');
        $this->assertSame(
            [ActionKindEnum::RECEIVER->value],
            array_values(array_unique($receiverKinds)),
            'Every ProcessWebhookJob subclass must be catalogued as a receiver, not a workflow step.'
        );
    }

    public function testSyncWritesMetadataOntoRowsThatAlreadyExist(): void
    {
        // A row created before the attribute carried metadata — the case this migration exists for.
        Action::query()->where('model_name', PushMessageToWordPressActivity::class)->delete();

        $stale = Action::query()->create([
            'name' => 'Stale Name',
            'model_name' => PushMessageToWordPressActivity::class,
            'kind' => ActionKindEnum::WORKFLOW->value,
        ]);

        $this->artisan('kanvas:workflow-sync-actions')->assertSuccessful();

        $stale->refresh();

        $this->assertSame('Push Message To WordPress', $stale->name);
        $this->assertNotEmpty($stale->description);
        $this->assertSame('wordpress', $stale->integration);
        $this->assertContains(ConfigurationEnum::SITE_URL->value, $stale->requiredConfigKeys());
        $this->assertArrayHasKey('message_type_id', $stale->paramDescriptions());
    }

    public function testCatalogReportsAnUnconfiguredIntegrationWithTheKeysToSet(): void
    {
        $this->syncCatalog();

        $entry = $this->entryNamed(
            $this->listOptions('actions', 'wordpress')['actions'] ?? [],
            'Push Message To WordPress'
        );

        $this->assertNotNull($entry, 'The WordPress step was not returned by the actions catalog.');
        $this->assertSame('wordpress', $entry['integration']);
        $this->assertFalse($entry['configured']);
        $this->assertStringContainsString(ConfigurationEnum::APPLICATION_PASSWORD->value, $entry['to_configure']);
        $this->assertArrayHasKey('message_type_id', $entry['params']);
    }

    public function testCatalogReportsConfiguredOnceTheCompanyHasTheKeys(): void
    {
        $this->syncCatalog();

        $company = $this->currentCompany();
        $company->set(ConfigurationEnum::SITE_URL->value, 'https://example.test');
        $company->set(ConfigurationEnum::USERNAME->value, 'editor');
        $company->set(ConfigurationEnum::APPLICATION_PASSWORD->value, 'secret');

        $entry = $this->entryNamed(
            $this->listOptions('actions', 'wordpress')['actions'] ?? [],
            'Push Message To WordPress'
        );

        $this->assertNotNull($entry);
        $this->assertTrue($entry['configured']);
        $this->assertArrayNotHasKey('to_configure', $entry);
    }

    /**
     * A step with no settings of its own still reports how to make it usable — "configured: false"
     * with nothing actionable beside it reads to an agent as "avoid this step", and the steps with no
     * settings are the core ones it most needs.
     */
    public function testAStepWithNoSettingsStillSaysHowToMakeItUsable(): void
    {
        $this->syncCatalog();

        $entry = $this->entryNamed(
            $this->listOptions('actions', 'Run Agent')['actions'] ?? [],
            'Run Agent On Record'
        );

        $this->assertNotNull($entry, 'Run Agent On Record is missing from the catalog.');
        $this->assertArrayHasKey('to_configure', $entry);
        $this->assertNotEmpty($entry['to_configure']);
        $this->assertStringContainsString('internal', $entry['to_configure']);
    }

    public function testReceiversAreListedSeparatelyFromWorkflowSteps(): void
    {
        $this->syncCatalog();

        $options = $this->listOptions('all');

        $this->assertArrayHasKey('actions', $options);
        $this->assertArrayHasKey('receivers', $options);

        $actionNames = array_column($options['actions'], 'name');
        $receiverNames = array_column($options['receivers'], 'name');

        $this->assertNotEmpty($receiverNames, 'No receivers were catalogued.');
        $this->assertSame(
            [],
            array_values(array_intersect($actionNames, $receiverNames)),
            'A receiver must never be offered as a workflow step.'
        );

        foreach ($options['receivers'] as $receiver) {
            $this->assertArrayHasKey('endpoints_configured', $receiver);
        }
    }

    public function testAHandlerCannotBeCataloguedTwice(): void
    {
        $this->syncCatalog();

        $this->expectException(QueryException::class);

        Action::query()->create([
            'name' => 'Push Message To WordPress (duplicate)',
            'model_name' => PushMessageToWordPressActivity::class,
            'kind' => ActionKindEnum::WORKFLOW->value,
        ]);
    }

    public function testTwoHandlersNeverShareADisplayName(): void
    {
        $names = array_column(new WorkflowActionDiscoveryService()->discover(), 'name');

        $collisions = array_filter(array_count_values($names), fn (int $n): bool => $n > 1);

        $this->assertSame(
            [],
            $collisions,
            'These names resolve to more than one handler, so a rule built from one would wire an '
            . "arbitrary handler:\n  - " . implode("\n  - ", array_keys($collisions))
        );
    }

    /**
     * The second pass exists for two handlers sharing a basename INSIDE one connector, which the
     * connector prefix alone cannot separate. It falls back to the namespace path, unique because
     * `model_name` is — so disambiguation always terminates.
     */
    public function testTwoHandlersInOneConnectorAreStillToldApart(): void
    {
        $service = new WorkflowActionDiscoveryService();
        $disambiguate = new ReflectionMethod($service, 'disambiguate');

        $first = 'Kanvas\Connectors\Elead\Workflow\PushLeadActivity';
        $second = 'Kanvas\Connectors\Elead\Activities\PushLeadActivity';

        $this->assertSame(
            $disambiguate->invoke($service, $first, false),
            $disambiguate->invoke($service, $second, false),
            'The connector prefix alone should tie here — that is why a second pass exists.'
        );

        $this->assertNotSame(
            $disambiguate->invoke($service, $first, true),
            $disambiguate->invoke($service, $second, true),
            'The namespace fallback must separate them.'
        );
    }

    /**
     * Asserted against the algorithm rather than a real class family: as descriptions get written, the
     * classes that used to collide are given explicit names and stop colliding, which would silently
     * turn a test pinned to one of them into a test of nothing.
     */
    public function testABareBasenameIsQualifiedByItsConnector(): void
    {
        $service = new WorkflowActionDiscoveryService();
        $disambiguate = new ReflectionMethod($service, 'disambiguate');

        $this->assertSame(
            'Elead PushLeadActivity',
            $disambiguate->invoke($service, 'Kanvas\Connectors\Elead\Workflow\PushLeadActivity', false)
        );
    }

    /**
     * A human can still wire legacy through the admin surface, and existing rules that point at it
     * keep running — but an agent builds on the current infrastructure only. A `Gewaer\…` handler has
     * no class left to run, so a rule assembled from one can never fire.
     */
    public function testLegacyHandlersAreNeverOfferedToAnAgent(): void
    {
        $legacy = Action::query()->create([
            'name' => 'Legacy Send Mail ' . fake()->unique()->uuid(),
            'model_name' => 'Gewaer\Actions\SendMail' . fake()->unique()->lexify('?????'),
            'kind' => ActionKindEnum::WORKFLOW->value,
        ]);

        $names = array_column($this->listOptions('actions', 'Legacy Send Mail')['actions'] ?? [], 'name');

        $this->assertNotContains($legacy->name, $names, 'A legacy handler was offered as a workflow step.');
    }

    public function testALegacyHandlerCannotBeWiredEvenWhenNamedDirectly(): void
    {
        $legacy = Action::query()->create([
            'name' => 'Legacy Zoho ' . fake()->unique()->uuid(),
            'model_name' => 'Gewaer\Domains\Integrations\Zoho\Actions\Leads' . fake()->unique()->lexify('?????'),
            'kind' => ActionKindEnum::WORKFLOW->value,
        ]);

        $tool = new ListWorkflowOptionsTool()
            ->withContext(app(Apps::class), $this->currentCompany(), $this->currentUser());

        $resolve = new ReflectionMethod($tool, 'resolveAction');

        $this->assertNull(
            $resolve->invoke($tool, $legacy->name),
            'Naming a legacy handler directly must not resolve it.'
        );
    }

    /**
     * The same entity exists twice — `Kanvas\Guild\Leads\Models\Lead` and `gewaer-models-leads` — and
     * an agent shown both cannot tell which one is live.
     */
    public function testLegacyEntitiesAreNotOfferedAlongsideTheirKanvasEquivalent(): void
    {
        $entities = $this->listOptions('entities', 'lead')['entities'] ?? [];

        $this->assertNotEmpty($entities);

        foreach ($entities as $name) {
            $this->assertStringNotContainsStringIgnoringCase('gewaer', $name);
            $this->assertStringNotContainsStringIgnoringCase('canvas\\', $name);
        }
    }

    /**
     * The entity list is capped and reads as "these are the options". Ordering by name and taking the
     * first 50 hid the real Message entity at position 66 of 77, so an agent was told it did not
     * exist. A search term has to reach past the cap.
     */
    public function testASearchFindsAnEntityThatSortsPastTheCap(): void
    {
        $module = $this->ensureMessageIsAnEntityOfThisApp();

        $entities = $this->listOptions('entities', 'Message')['entities'] ?? [];

        $this->assertContains(
            $module->name,
            $entities,
            'A search for "Message" must surface the real Message entity regardless of where it sorts.'
        );
    }

    /**
     * A caller naming a class was previously searching display names only, so a fully-qualified name
     * matched nothing at all.
     */
    public function testAnEntityIsFindableByItsClassName(): void
    {
        $module = $this->ensureMessageIsAnEntityOfThisApp();

        $entities = $this->listOptions('entities', Message::class)['entities'] ?? [];

        $this->assertContains($module->name, $entities);
    }

    /**
     * A model copies a class name back exactly as it saw it, and it saw it inside JSON — where the
     * separators are doubled. Rejecting that produced the worst error the catalog can give: listing
     * an entity and then refusing the very string it had just handed over.
     */
    public function testAnEntityResolvesWhenItsBackslashesArriveJsonEscaped(): void
    {
        $tool = new ListWorkflowOptionsTool()
            ->withContext(app(Apps::class), $this->currentCompany(), $this->currentUser());

        $resolve = new ReflectionMethod($tool, 'resolveSystemModule');

        $plain = $resolve->invoke($tool, Message::class);
        $escaped = $resolve->invoke($tool, str_replace('\\', '\\\\', Message::class));

        $this->assertNotNull($plain, 'The plain class name must resolve.');
        $this->assertNotNull($escaped, 'A JSON-escaped class name must resolve to the same entity.');
        $this->assertSame($plain->getId(), $escaped->getId());
    }

    public function testEverythingTheEntityListOffersCanAlsoBeResolved(): void
    {
        $tool = new ListWorkflowOptionsTool()
            ->withContext(app(Apps::class), $this->currentCompany(), $this->currentUser());

        $resolve = new ReflectionMethod($tool, 'resolveSystemModule');
        $offered = $this->listOptions('entities', 'Message')['entities'] ?? [];

        $this->assertNotEmpty($offered);

        foreach ($offered as $name) {
            $this->assertNotNull(
                $resolve->invoke($tool, $name),
                "The catalog offered \"{$name}\" but the create tool cannot resolve it."
            );
        }
    }

    public function testAPhantomEntityIsNeverOffered(): void
    {
        $entities = $this->listOptions('entities', 'Message')['entities'] ?? [];

        foreach ($entities as $name) {
            $module = SystemModules::query()
                ->fromApp(app(Apps::class))
                ->where('name', $name)
                ->first();

            if ($module !== null) {
                $this->assertTrue(
                    class_exists((string) $module->model_name),
                    "Offered entity {$name} points at a class that does not exist."
                );
            }
        }
    }

    public function testAnUnknownCatalogNamesTheValidOnes(): void
    {
        $options = $this->listOptions('nonsense');

        $this->assertSame('error', $options['status']);
        $this->assertStringContainsString('receivers', $options['message']);
    }

    private function syncCatalog(): void
    {
        $this->artisan('kanvas:workflow-sync-actions')->assertSuccessful();
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * `system_modules` is per-app and seeded per deployment, so which entities a given app exposes is
     * environment data, not a fact about the code. These tests are about how the SEARCH behaves once
     * an entity is there — asserting against whatever the environment happens to hold passes on a
     * developer's tenant and fails on a fresh CI database.
     *
     * The module is RETURNED so callers assert against its own `name`. The catalog lists entities by
     * that column, and what lands there differs by environment — some seeded rows carry a slug rather
     * than the class name — so hardcoding the FQCN as the expected label tests the seeding, not the
     * search.
     */
    private function ensureMessageIsAnEntityOfThisApp(): SystemModules
    {
        $app = app(Apps::class);

        $existing = SystemModules::query()
            ->where('model_name', Message::class)
            ->fromApp($app)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $module = new SystemModules();
        $module->name = Message::class;
        $module->model_name = Message::class;
        $module->apps_id = $app->getId();
        $module->saveOrFail();

        // Read back rather than trust the in-memory value: traits and observers on this model can
        // rewrite `name` on save, and the catalog lists entities by whatever actually landed.
        return $module->refresh();
    }

    private function listOptions(string $kind, ?string $search = null): array
    {
        $user = $this->currentUser();

        return new ListWorkflowOptionsTool()
            ->withContext(app(Apps::class), $this->currentCompany(), $user)
            ->__invoke($kind, $search);
    }

    private function currentUser(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }

    private function currentCompany(): Companies
    {
        return $this->currentUser()->getCurrentCompany();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function discoveredEntryFor(string $class): ?array
    {
        foreach (new WorkflowActionDiscoveryService()->discover() as $entry) {
            if ($entry['class'] === $class) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>|null
     */
    private function entryNamed(array $entries, string $name): ?array
    {
        foreach ($entries as $entry) {
            if (($entry['name'] ?? null) === $name) {
                return $entry;
            }
        }

        return null;
    }
}
