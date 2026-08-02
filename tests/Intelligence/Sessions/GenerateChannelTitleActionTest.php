<?php

declare(strict_types=1);

namespace Tests\Intelligence\Sessions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Sessions\Actions\GenerateChannelTitleAction;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
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

    private function makeUnsavedChannel(string $name): Channel
    {
        $channel = new Channel();
        $channel->name = $name;

        return $channel;
    }
}
