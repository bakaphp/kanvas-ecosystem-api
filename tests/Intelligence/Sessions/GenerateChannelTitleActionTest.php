<?php

declare(strict_types=1);

namespace Tests\Intelligence\Sessions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Sessions\Actions\GenerateChannelTitleAction;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;
use Laravel\Ai\AnonymousAgent;
use Tests\TestCase;

class GenerateChannelTitleActionTest extends TestCase
{
    use DatabaseTransactions;

    public function testRenamesDefaultChannelWithGeneratedTitle(): void
    {
        AnonymousAgent::fake(['Trade-In Value For Honda Civic']);

        $channel = $this->createChannel('AI chat with Sally');

        $title = new GenerateChannelTitleAction(
            $channel,
            'How much is my 2018 Civic worth on trade-in?',
            'A 2018 Honda Civic typically trades in around $15k depending on mileage.',
        )->execute();

        $this->assertSame('Trade-In Value For Honda Civic', $title);

        $channel->refresh();
        $this->assertSame('Trade-In Value For Honda Civic', $channel->name);
        $this->assertTrue(($channel->metadata['auto_titled'] ?? false) === true);
        // The generated title is remembered so a later refine can tell it apart from a human rename.
        $this->assertSame('Trade-In Value For Honda Civic', $channel->metadata['auto_title'] ?? null);
        $this->assertArrayNotHasKey('title_finalized', $channel->metadata ?? []);
    }

    public function testRefineRetitlesFromRecentConversationThenFinalizes(): void
    {
        AnonymousAgent::fake(['Financing Options For SUV']);

        $channel = $this->createChannel('AI chat with Sally');
        $channel->name = 'Rough Opening Title';
        $channel->metadata = ['auto_titled' => true, 'auto_title' => 'Rough Opening Title'];
        $channel->saveOrFail();

        $this->addMessageToChannel($channel, 'What SUVs do you have under 40k?', fromIa: false);
        $this->addMessageToChannel($channel, 'We have several. Are you financing or paying cash?', fromIa: true);
        $this->addMessageToChannel($channel, 'Financing, what are my options?', fromIa: false);

        $title = new GenerateChannelTitleAction($channel, '', '', refine: true)->execute();

        $this->assertSame('Financing Options For SUV', $title);

        $channel->refresh();
        $this->assertSame('Financing Options For SUV', $channel->name);
        $this->assertSame('Financing Options For SUV', $channel->metadata['auto_title'] ?? null);
        $this->assertTrue(($channel->metadata['title_finalized'] ?? false) === true);
    }

    public function testRefineCatchesUpChannelThatMissedItsFirstTitle(): void
    {
        // Predates the feature (or its first-pass job failed): still system-default-named, no metadata,
        // already well past the opening exchange. The refine pass must still title it from context.
        AnonymousAgent::fake(['Warranty Coverage Questions']);

        $channel = $this->createChannel('Chat with Sally');
        $this->assertNull($channel->metadata);

        $this->addMessageToChannel($channel, 'Does the warranty cover the transmission?', fromIa: false);
        $this->addMessageToChannel($channel, 'Yes, the powertrain warranty covers it for 5 years.', fromIa: true);
        $this->addMessageToChannel($channel, 'And what about the battery?', fromIa: false);

        $title = new GenerateChannelTitleAction($channel, '', '', refine: true)->execute();

        $this->assertSame('Warranty Coverage Questions', $title);

        $channel->refresh();
        $this->assertSame('Warranty Coverage Questions', $channel->name);
        $this->assertTrue(($channel->metadata['title_finalized'] ?? false) === true);
    }

    public function testRefineSkippedWhenHumanRenamedSinceAutoTitle(): void
    {
        AnonymousAgent::fake(['Should Not Be Used']);

        $channel = $this->createChannel('AI chat with Sally');
        // We auto-titled it, then a human renamed it — name no longer matches our stored auto_title.
        $channel->name = 'My Own Name';
        $channel->metadata = ['auto_titled' => true, 'auto_title' => 'The Title We Generated'];
        $channel->saveOrFail();

        $this->addMessageToChannel($channel, 'hello', fromIa: false);

        $title = new GenerateChannelTitleAction($channel, '', '', refine: true)->execute();

        $this->assertNull($title);

        $channel->refresh();
        $this->assertSame('My Own Name', $channel->name);
    }

    public function testRefineSkippedWhenAlreadyFinalized(): void
    {
        AnonymousAgent::fake(['Should Not Be Used']);

        $channel = $this->createChannel('AI chat with Sally');
        $channel->name = 'Final Title';
        $channel->metadata = [
            'auto_titled' => true,
            'auto_title' => 'Final Title',
            'title_finalized' => true,
        ];
        $channel->saveOrFail();

        $title = new GenerateChannelTitleAction($channel, '', '', refine: true)->execute();

        $this->assertNull($title);

        $channel->refresh();
        $this->assertSame('Final Title', $channel->name);
    }

    public function testCanRefineOnlyWhenTitleIsStillOursAndNotFinalized(): void
    {
        $stillOurs = $this->makeUnsavedChannel('Our Title');
        $stillOurs->metadata = ['auto_titled' => true, 'auto_title' => 'Our Title'];
        $this->assertTrue(GenerateChannelTitleAction::canRefine($stillOurs));

        $finalized = $this->makeUnsavedChannel('Our Title');
        $finalized->metadata = ['auto_titled' => true, 'auto_title' => 'Our Title', 'title_finalized' => true];
        $this->assertFalse(GenerateChannelTitleAction::canRefine($finalized));

        $humanRenamed = $this->makeUnsavedChannel('Human Name');
        $humanRenamed->metadata = ['auto_titled' => true, 'auto_title' => 'Our Title'];
        $this->assertFalse(GenerateChannelTitleAction::canRefine($humanRenamed));

        $neverTitled = $this->makeUnsavedChannel('AI chat with Sally');
        $this->assertFalse(GenerateChannelTitleAction::canRefine($neverTitled));
    }

    public function testKeepsSlugStableWhenTitling(): void
    {
        AnonymousAgent::fake(['Some Title']);

        $channel = $this->createChannel('Chat with Sally');
        $originalSlug = $channel->slug;

        new GenerateChannelTitleAction($channel, 'hello', 'hi there')->execute();

        $channel->refresh();
        $this->assertSame($originalSlug, $channel->slug, 'The slug drives the session uuid and must never change.');
    }

    public function testSkipsWhenNameIsNotADefault(): void
    {
        AnonymousAgent::fake(['Should Not Be Used']);

        $channel = $this->createChannel('My Custom Renamed Chat');

        $title = new GenerateChannelTitleAction($channel, 'hello', 'hi there')->execute();

        $this->assertNull($title);

        $channel->refresh();
        $this->assertSame('My Custom Renamed Chat', $channel->name);
    }

    public function testHasDefaultNameMatchesEveryInAppPrefix(): void
    {
        $this->assertTrue(GenerateChannelTitleAction::hasDefaultName($this->makeUnsavedChannel('AI chat with Sally')));
        $this->assertTrue(GenerateChannelTitleAction::hasDefaultName($this->makeUnsavedChannel('Chat with Sally')));
        $this->assertTrue(GenerateChannelTitleAction::hasDefaultName($this->makeUnsavedChannel('Conversation with Jane Doe')));
        $this->assertFalse(GenerateChannelTitleAction::hasDefaultName($this->makeUnsavedChannel('Trade-In Value For Honda Civic')));
    }

    private function createChannel(string $name): Channel
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return new CreateChannelAction(
            new ChannelDto(
                apps: $app,
                companies: $company,
                users: $user,
                entity_id: $user->getId(),
                entity_namespace: Users::class,
                name: $name,
                description: $name,
                slug: 'ai-assist-test-' . uniqid(),
            )
        )->execute();
    }

    private function addMessageToChannel(Channel $channel, string $content, bool $fromIa): Message
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $type = MessageTypeService::getOrCreate($app, 'ai-chat');

        /** @var Message $message */
        $message = Message::factory()
            ->withMessageType($type)
            ->create([
                'message' => ['content' => $content, 'from_ia' => $fromIa],
            ]);

        $channel->messages()->attach($message->getId(), ['users_id' => $user->getId()]);

        return $message;
    }

    private function makeUnsavedChannel(string $name): Channel
    {
        $channel = new Channel();
        $channel->name = $name;

        return $channel;
    }
}
