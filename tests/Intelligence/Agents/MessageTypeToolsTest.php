<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Tools\Social\CreateMessageTypeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Social\ListMessageTypesTool;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use Tests\TestCase;
use Throwable;

final class MessageTypeToolsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'social', 'workflow'];

    public function testCreatesATypeAndSaysNothingRunsOnItYet(): void
    {
        $verb = 'smoke-type-' . fake()->unique()->lexify('?????');

        $result = $this->creator()->__invoke(verb: $verb, name: 'Smoke Type');

        $this->assertSame('success', $result['status'], json_encode($result));
        $this->assertTrue($result['created']);
        $this->assertSame($verb, $result['verb']);
        $this->assertSame('Smoke Type', $result['name']);
        $this->assertStringContainsString('Nothing runs on this type yet', $result['note']);
    }

    public function testCreatingAnExistingTypeIsANoOpRatherThanADuplicate(): void
    {
        $verb = 'smoke-type-' . fake()->unique()->lexify('?????');
        $first = $this->creator()->__invoke(verb: $verb);

        $second = $this->creator()->__invoke(verb: strtoupper($verb), name: 'Different name');

        $this->assertSame('success', $second['status']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['message_type_id'], $second['message_type_id']);
        $this->assertSame(
            1,
            MessageType::query()
                ->where('apps_id', $this->kanvasApp()->getId())
                ->whereRaw('LOWER(verb) = ?', [$verb])
                ->count()
        );
    }

    public function testAnEmptyVerbIsRefused(): void
    {
        $result = $this->creator()->__invoke(verb: '   ');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('verb is empty', $result['message']);
    }

    public function testListingReturnsTheVerbsAnAgentCanWriteTo(): void
    {
        $type = $this->articleType();

        $result = $this->lister()->__invoke(search: $type->verb);

        $this->assertSame('success', $result['status']);

        $verbs = array_column($result['types'], 'verb');
        $this->assertContains($type->verb, $verbs);

        $entry = $this->entryFor($result['types'], $type->verb);
        $this->assertArrayHasKey('messages', $entry);
        $this->assertArrayHasKey('automated', $entry);
    }

    /**
     * The signal that matters: a type nothing watches is just a label, and an agent writing to it
     * gets a saved record and no effect.
     */
    public function testATypeIsOnlyAutomatedOnceARuleIsPointedAtIt(): void
    {
        $type = $this->articleType();

        $before = $this->entryFor($this->lister()->__invoke(search: $type->verb)['types'], $type->verb);
        $this->assertFalse($before['automated']);

        $this->ruleWatching($type);

        $after = $this->entryFor($this->lister()->__invoke(search: $type->verb)['types'], $type->verb);
        $this->assertTrue($after['automated'], 'A rule carrying this message_type_id should mark it automated.');
    }

    public function testAutomatedOnlyHidesTheTypesNothingIsWatching(): void
    {
        $watched = $this->articleType();
        $ignored = MessageTypeService::getOrCreate(
            app: $this->kanvasApp(),
            verb: 'unwatched-' . fake()->unique()->lexify('?????')
        );

        $this->ruleWatching($watched);

        $verbs = array_column($this->lister()->__invoke(automated_only: true)['types'], 'verb');

        $this->assertContains($watched->verb, $verbs);
        $this->assertNotContains($ignored->verb, $verbs);
    }

    /**
     * @param list<array<string, mixed>> $types
     * @return array<string, mixed>
     */
    private function entryFor(array $types, string $verb): array
    {
        foreach ($types as $type) {
            if ($type['verb'] === $verb) {
                return $type;
            }
        }

        $this->fail("The message type {$verb} was not listed.");
    }

    private function ruleWatching(MessageType $type): Rule
    {
        return Rule::create([
            'apps_id' => $this->kanvasApp()->getId(),
            'companies_id' => $this->company()->getId(),
            'systems_modules_id' => $this->systemModule()->getId(),
            'rules_types_id' => $this->ruleType()->getId(),
            'name' => 'Publish ' . $type->verb . ' ' . fake()->unique()->uuid(),
            'description' => 'Smoke test rule',
            'pattern' => '1',
            'params' => ['message_type_id' => $type->getId(), 'status' => 'pending'],
            'is_async' => 1,
            'is_deleted' => 0,
        ]);
    }

    private function articleType(): MessageType
    {
        return MessageTypeService::getOrCreate(
            app: $this->kanvasApp(),
            verb: 'news-article-' . fake()->unique()->lexify('?????')
        );
    }

    private function systemModule(): SystemModules
    {
        return SystemModules::query()->where('apps_id', $this->kanvasApp()->getId())->firstOrFail();
    }

    private function ruleType(): RuleType
    {
        try {
            return RuleType::getByName('created');
        } catch (Throwable) {
            return RuleType::factory()->create();
        }
    }

    private function lister(): ListMessageTypesTool
    {
        return new ListMessageTypesTool()->withContext($this->kanvasApp(), $this->company(), $this->currentUser());
    }

    private function creator(): CreateMessageTypeTool
    {
        return new CreateMessageTypeTool()->withContext($this->kanvasApp(), $this->company(), $this->currentUser());
    }

    private function kanvasApp(): Apps
    {
        return app(Apps::class);
    }

    private function company(): Companies
    {
        return $this->currentUser()->getCurrentCompany();
    }

    private function currentUser(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }
}
