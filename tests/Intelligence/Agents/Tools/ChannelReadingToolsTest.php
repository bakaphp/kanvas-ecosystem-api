<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Tools\Social\ReadChannelWindowTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Social\ReadThreadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Social\SearchChannelMessagesTool;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * From a DM the agent knows a room by its name or Slack id, never by a Kanvas channel_id, and a
 * question about "what Jose said" or "why that happened" needs more than the newest page.
 */
final class ChannelReadingToolsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'social'];

    public function testTheWindowResolvesARoomByItsNameWithOrWithoutTheHash(): void
    {
        $suffix = strtolower(Str::random(8));
        $channel = $this->channel(name: '#ops-' . $suffix, slug: 'slack-t1-c' . $suffix);
        $this->messageOn($channel, 'Deploy went out.');

        $withHash = $this->window()->__invoke(channel: '#ops-' . $suffix);
        $this->assertSame('success', $withHash['status']);
        $this->assertSame($channel->getId(), $withHash['channel_id']);

        $bare = $this->window()->__invoke(channel: 'ops-' . $suffix);
        $this->assertSame($channel->getId(), $bare['channel_id']);
    }

    public function testTheWindowResolvesASlackRoomByItsSlackChannelId(): void
    {
        $slackId = 'C' . strtoupper(Str::random(10));
        $channel = $this->channel(name: '#agents-logs-' . strtolower($slackId), slug: 'slack-t02rcjy0q-' . strtolower($slackId));
        $this->messageOn($channel, 'Jose: the cron died at 3am');

        $result = $this->window()->__invoke(channel: $slackId);

        $this->assertSame('success', $result['status']);
        $this->assertSame($channel->getId(), $result['channel_id']);
        $this->assertSame('Jose: the cron died at 3am', $result['messages'][0]['text']);
    }

    public function testAnUnknownRoomNameIsAnErrorThatSaysNotToRetry(): void
    {
        $result = $this->window()->__invoke(channel: '#no-such-room-' . Str::random(6));

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Do not retry', $result['message']);
    }

    public function testARoomOfAnotherCompanyIsNotResolvableByName(): void
    {
        $name = '#foreign-' . strtolower(Str::random(8));
        $this->channel(name: $name, slug: 'slack-t9-c' . Str::random(8), company: Companies::factory()->create());

        $result = $this->window()->__invoke(channel: $name);

        $this->assertSame('error', $result['status']);
    }

    public function testSearchFindsWordsCaseInsensitivelyAndNewestFirst(): void
    {
        $channel = $this->channel();
        $this->messageOn($channel, 'Lunch at noon?');
        $older = $this->messageOn($channel, 'The DEPLOY failed on staging');
        $newer = $this->messageOn($channel, 'deploy is green again');

        $result = $this->search()->__invoke(channel_id: $channel->getId(), query: 'Deploy');

        $this->assertSame('success', $result['status']);
        $this->assertSame([$newer->getId(), $older->getId()], array_column($result['messages'], 'message_id'));
    }

    public function testSearchByAuthorMatchesTheWriterAndTheNamePrefixOfAnUnresolvedSpeaker(): void
    {
        $channel = $this->channel();
        $jose = Users::factory()->create([
            'firstname' => 'Jose',
            'lastname' => 'Perez',
            'displayname' => 'jperez',
        ]);

        $byJose = $this->messageOn($channel, 'I rolled it back');
        $byJose->users_id = $jose->getId();
        $byJose->saveQuietly();

        $attributed = $this->messageOn($channel, 'Jose Perez: because the migration locked the table');
        $this->messageOn($channel, 'Maria: thanks');

        $result = $this->search()->__invoke(channel_id: $channel->getId(), author: 'jose');

        $this->assertSame('success', $result['status']);
        $this->assertEqualsCanonicalizing(
            [$byJose->getId(), $attributed->getId()],
            array_column($result['messages'], 'message_id')
        );
    }

    public function testSearchSinceExcludesOlderMessagesAndRejectsABadDate(): void
    {
        $channel = $this->channel();
        $old = $this->messageOn($channel, 'last week standup');
        $old->created_at = Carbon::now()->subDays(10);
        $old->saveQuietly();
        $recent = $this->messageOn($channel, 'today standup');

        $result = $this->search()->__invoke(
            channel_id: $channel->getId(),
            query: 'standup',
            since: Carbon::now()->subDays(2)->toDateString(),
        );

        $this->assertSame([$recent->getId()], array_column($result['messages'], 'message_id'));

        $bad = $this->search()->__invoke(channel_id: $channel->getId(), since: 'last tuesday-ish');
        $this->assertSame('error', $bad['status']);
    }

    public function testSearchWithNothingToFilterOnIsRefusedAndAnEmptyResultSaysNotToRepeat(): void
    {
        $channel = $this->channel();
        $this->messageOn($channel, 'hello');

        $refused = $this->search()->__invoke(channel_id: $channel->getId());
        $this->assertSame('error', $refused['status']);

        $empty = $this->search()->__invoke(channel_id: $channel->getId(), query: 'zzz-' . Str::random(8));
        $this->assertSame('success', $empty['status']);
        $this->assertSame(0, $empty['returned']);
        $this->assertStringContainsString('will find nothing more', $empty['message']);
    }

    public function testAThreadReadFromAReplyResolvesTheRootAndListsRepliesOldestFirst(): void
    {
        $channel = $this->channel();
        $root = $this->messageOn($channel, 'Why did the import stop?');
        $first = $this->reply($channel, $root, 'The FTP password rotated.');
        $second = $this->reply($channel, $root, 'Fixed, re-running now.');

        $result = $this->thread()->__invoke(message_id: $second->getId());

        $this->assertSame('success', $result['status']);
        $this->assertSame($root->getId(), $result['root']['message_id']);
        $this->assertSame([$first->getId(), $second->getId()], array_column($result['replies'], 'message_id'));
        $this->assertFalse($result['has_more']);
    }

    public function testAThreadWhoseRootWasNeverStoredFallsBackToTheSlackThreadTimestamp(): void
    {
        $channel = $this->channel();
        $ts = '1760000000.' . random_int(100000, 999999);
        $slackChannel = 'C' . strtoupper(Str::random(9));

        $orphanA = $this->messageOn($channel, 'Hector: root is older than the invite', [
            'slack_channel' => $slackChannel,
            'slack_thread_ts' => $ts,
        ]);
        $orphanB = $this->messageOn($channel, 'Scott: still, we can read the replies', [
            'slack_channel' => $slackChannel,
            'slack_thread_ts' => $ts,
        ]);

        $result = $this->thread()->__invoke(message_id: $orphanA->getId());

        $this->assertSame($orphanA->getId(), $result['root']['message_id']);
        $this->assertSame([$orphanB->getId()], array_column($result['replies'], 'message_id'));
    }

    public function testAMessageOfAnotherCompanyCannotBeReadAsAThread(): void
    {
        $foreign = Companies::factory()->create();
        $channel = $this->channel(company: $foreign);
        $message = $this->messageOn($channel, 'private');
        $message->companies_id = $foreign->getId();
        $message->saveQuietly();

        $result = $this->thread()->__invoke(message_id: $message->getId());

        $this->assertSame('error', $result['status']);
    }

    private function window(): ReadChannelWindowTool
    {
        return new ReadChannelWindowTool()->withContext($this->kanvasApp(), $this->company(), $this->currentUser());
    }

    private function search(): SearchChannelMessagesTool
    {
        return new SearchChannelMessagesTool()->withContext($this->kanvasApp(), $this->company(), $this->currentUser());
    }

    private function thread(): ReadThreadTool
    {
        return new ReadThreadTool()->withContext($this->kanvasApp(), $this->company(), $this->currentUser());
    }

    private function channel(?string $name = null, ?string $slug = null, ?Companies $company = null): Channel
    {
        $slug ??= 'room-' . fake()->unique()->uuid();

        return Channel::create([
            'name' => $name ?? $slug,
            'slug' => $slug,
            'description' => 'Room used in tests',
            'apps_id' => $this->kanvasApp()->getId(),
            'companies_id' => ($company ?? $this->company())->getId(),
            'users_id' => $this->currentUser()->getId(),
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function messageOn(Channel $channel, string $text, array $extra = []): Message
    {
        $action = new CreateMessageAction(new MessageInput(
            app: $this->kanvasApp(),
            company: $this->company(),
            user: $this->currentUser(),
            type: $this->chatType(),
            message: ['content' => $text, ...$extra],
        ));
        $action->runWorkflow = false;
        $message = $action->execute();

        $channel->addMessage($message, $this->currentUser());

        return $message;
    }

    private function reply(Channel $channel, Message $root, string $text): Message
    {
        $reply = $this->messageOn($channel, $text);
        $reply->parent_id = $root->getId();
        $reply->saveQuietly();

        return $reply;
    }

    private function chatType(): MessageType
    {
        return MessageTypeService::getOrCreate(app: $this->kanvasApp(), verb: 'group-chat');
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
