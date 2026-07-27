<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\Actions\ParseMessageMentionsAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Events\MessageMentionsStoredEvent;
use Kanvas\Social\Messages\Jobs\ProcessMessageMentionsJob;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\UsersAssociatedApps;
use Tests\TestCase;

class ParseMessageMentionsActionTest extends TestCase
{
    private function makeMessage(string $content, bool $fromIa = false): Message
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $action = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $company,
                user: $user,
                type: MessageTypeService::getOrCreate($app, 'note'),
                message: ['content' => $content, 'from_ia' => $fromIa],
                is_public: 1,
            ),
        );
        $action->runWorkflow = false;

        return $action->execute();
    }

    private function nameCurrentUser(string $displayname): int
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        UsersAssociatedApps::where('users_id', $user->getId())
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->update(['displayname' => $displayname]);

        return $user->getId();
    }

    public function testResolvesAndStoresMentionByDisplayname(): void
    {
        Event::fake([MessageMentionsStoredEvent::class]);
        $userId = $this->nameCurrentUser('MentionTarget');

        $message = $this->makeMessage('hey @MentionTarget can you look at this');
        $result = new ParseMessageMentionsAction($message)->execute();

        $this->assertContains($userId, $result);
        $this->assertContains($userId, (array) $message->get('mentions'));
        $this->assertTrue($message->tags()->exists());
        Event::assertDispatched(MessageMentionsStoredEvent::class);
    }

    public function testExtractsMentionFromARawStringBody(): void
    {
        Event::fake([MessageMentionsStoredEvent::class]);
        $userId = $this->nameCurrentUser('StringHandle');

        // Some apps store the body as a raw (HTML) string, not a {content} object.
        $message = $this->makeMessage('placeholder');
        DB::connection($message->getConnectionName())->table('messages')
            ->where('id', $message->getId())
            ->update(['message' => '<p>@StringHandle yo u free?</p>']);
        $message = $message->fresh();

        // getMessage() can't see a non-object body; contentText() must recover it.
        $this->assertSame([], $message->getMessage());
        $this->assertStringContainsString('@StringHandle', $message->contentText());

        $result = new ParseMessageMentionsAction($message)->execute();

        $this->assertContains($userId, $result);
        Event::assertDispatched(MessageMentionsStoredEvent::class);
    }

    public function testRawStringBodyIsPreservedAfterParsing(): void
    {
        $this->nameCurrentUser('StringHandle');

        $message = $this->makeMessage('placeholder');
        DB::connection($message->getConnectionName())->table('messages')
            ->where('id', $message->getId())
            ->update(['message' => '<p>@StringHandle yo u free?</p>']);
        $message = $message->fresh();

        new ParseMessageMentionsAction($message)->execute();

        // The original text must survive — parsing records mentions out-of-band.
        $this->assertStringContainsString('@StringHandle', $message->fresh()->contentText());
    }

    public function testNoMentionWhenNoDisplaynameMatches(): void
    {
        $this->nameCurrentUser('MentionTarget');

        $message = $this->makeMessage('hey @Nobody are you around');
        $result = new ParseMessageMentionsAction($message)->execute();

        $this->assertSame([], $result);
        $this->assertArrayNotHasKey('mentions', $message->fresh()->getMessage());
    }

    public function testCreatingMentionMessageDispatchesTheJob(): void
    {
        Bus::fake([ProcessMessageMentionsJob::class]);

        $this->makeMessage('hey @Someone please');

        Bus::assertDispatched(ProcessMessageMentionsJob::class);
    }

    public function testAgentAuthoredMessageStillParsesSoItCanNotifyHumans(): void
    {
        // Agent (from_ia) messages ARE parsed now — an agent must be able to @mention a human to
        // notify them. The anti-loop guard (agents never wake other agents) lives in
        // RespondToAgentMentionListener, which skips from_ia — not in this dispatch decision.
        Bus::fake([ProcessMessageMentionsJob::class]);

        $this->makeMessage('@Someone from the agent', fromIa: true);

        Bus::assertDispatched(ProcessMessageMentionsJob::class);
    }
}
