<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Slack;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Slack\Client;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCaseUnit;

/**
 * Slack sends no push, badge or unread marker for chat.update, so an agent reply delivered by
 * editing the placeholder arrived silently. These lock in that the answer goes out as a real post.
 */
final class SlackClientReplyDeliveryTest extends TestCaseUnit
{
    private const string CHANNEL = 'D0123456789';
    private const string PLACEHOLDER_TS = '1700000000.000100';

    public function testReplyIsPostedAsANewMessageAndThePlaceholderIsDeleted(): void
    {
        $this->fakeSlack();

        new Client('xoxb-test')->replacePlaceholderWithReply(
            self::CHANNEL,
            self::PLACEHOLDER_TS,
            'here is your answer',
        );

        $this->assertSlackCallCount('chat.postMessage', 1);
        $this->assertSlackCallCount('chat.update', 0);
        $this->assertSlackCallCount('chat.delete', 1);

        Http::assertSent(fn (Request $request) => $this->isSlackCall($request, 'chat.postMessage')
            && $request['channel'] === self::CHANNEL
            && $request['text'] === 'here is your answer'
            && ! isset($request['thread_ts']));

        Http::assertSent(fn (Request $request) => $this->isSlackCall($request, 'chat.delete')
            && $request['channel'] === self::CHANNEL
            && $request['ts'] === self::PLACEHOLDER_TS);
    }

    public function testThreadedReplyKeepsTheThreadOnEveryChunk(): void
    {
        $this->fakeSlack();

        $threadTs = '1700000000.000001';
        $text = implode("\n", array_fill(0, 4, str_repeat('a', 1000)));

        new Client('xoxb-test')->replacePlaceholderWithReply(
            self::CHANNEL,
            self::PLACEHOLDER_TS,
            $text,
            $threadTs,
        );

        $this->assertSlackCallCount('chat.postMessage', 2);
        $this->assertSlackCallCount('chat.delete', 1);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => ! $this->isSlackCall($request, 'chat.postMessage')
            || $request['thread_ts'] === $threadTs);
    }

    /**
     * Without a parent thread the follow-ups hang off the first posted chunk — the placeholder's ts
     * is gone by then, so threading on it would leave orphaned messages.
     */
    public function testUnthreadedOverflowHangsOffTheFirstPostedChunk(): void
    {
        $this->fakeSlack();

        $text = implode("\n", array_fill(0, 4, str_repeat('a', 1000)));

        new Client('xoxb-test')->replacePlaceholderWithReply(
            self::CHANNEL,
            self::PLACEHOLDER_TS,
            $text,
        );

        Http::assertSent(fn (Request $request) => ! $this->isSlackCall($request, 'chat.postMessage')
            || ($request['thread_ts'] ?? null) !== self::PLACEHOLDER_TS);

        Http::assertSent(fn (Request $request) => $this->isSlackCall($request, 'chat.postMessage')
            && ($request['thread_ts'] ?? null) === 'ts-1');
    }

    /**
     * A dead placeholder (already removed, or a token without delete rights) must not surface as a
     * failed reply — the answer is out, the caller would otherwise overwrite it with a false error.
     */
    public function testAFailingDeleteDoesNotFailTheDeliveredReply(): void
    {
        Http::fake([
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => 'ts-1']),
            'slack.com/api/chat.delete' => Http::response(['ok' => false, 'error' => 'message_not_found']),
        ]);

        new Client('xoxb-test')->replacePlaceholderWithReply(
            self::CHANNEL,
            self::PLACEHOLDER_TS,
            'here is your answer',
        );

        $this->assertSlackCallCount('chat.postMessage', 1);
        $this->assertSlackCallCount('chat.delete', 1);
    }

    public function testAFailingPostStillSurfacesSoTheCallerCanFallBack(): void
    {
        Http::fake([
            'slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'channel_not_found']),
        ]);

        $this->expectException(ValidationException::class);

        new Client('xoxb-test')->replacePlaceholderWithReply(
            self::CHANNEL,
            self::PLACEHOLDER_TS,
            'here is your answer',
        );
    }

    private function fakeSlack(): void
    {
        $posted = 0;

        Http::fake([
            'slack.com/api/chat.postMessage' => function () use (&$posted) {
                $posted++;

                return Http::response(['ok' => true, 'ts' => 'ts-' . $posted]);
            },
            'slack.com/api/*' => Http::response(['ok' => true]),
        ]);
    }

    private function isSlackCall(Request $request, string $method): bool
    {
        return $request->url() === 'https://slack.com/api/' . $method;
    }

    private function assertSlackCallCount(string $method, int $expected): void
    {
        $count = 0;
        Http::assertSent(function (Request $request) use ($method, &$count) {
            if ($this->isSlackCall($request, $method)) {
                $count++;
            }

            return true;
        });

        $this->assertSame($expected, $count, $method . ' call count');
    }
}
