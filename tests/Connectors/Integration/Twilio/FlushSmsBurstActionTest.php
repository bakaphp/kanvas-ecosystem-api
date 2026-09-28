<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Actions\FlushSmsBurstAction;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Tests\Stubs\Social\InterceptingChannel;
use Tests\TestCase;

/**
 * The seam between the burst and the agent. `AgentChannelResponderAction` reads `burst_text` and
 * falls back to the head's own body when it is absent — so a renamed or dropped key here does not
 * fail anything, it silently goes back to answering only the first text of a flurry.
 */
final class FlushSmsBurstActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = [null, 'social'];

    public function testItAnnouncesTheWholeBurstOnceOnTheChannelEvent(): void
    {
        $app = app(Apps::class);
        $channel = $this->recordingChannel();
        $messages = $this->burst($app, ['is the Navigator available?', 'the black one', 'Saturday?']);

        new FlushSmsBurstAction($app, $channel, $messages)->execute();

        $this->assertCount(1, $channel->fires, 'A closed burst announces itself exactly once');

        $params = $channel->firstFireOf(WorkflowEnum::AFTER_ADDING_MESSAGE_TO_CHANNEL->value);

        $this->assertNotNull($params, 'SMS announces on the plain channel event');
        $this->assertSame('sms', $params['communication_channel']);
        $this->assertSame(
            "is the Navigator available?\n\nthe black one\n\nSaturday?",
            $params['burst_text'],
            'burst_text is what the responder feeds the agent; without it only the head is answered'
        );
        $this->assertSame($params['burst_text'], $params['text']);
        $this->assertSame($messages->first()->getId(), $params['message']->getId(), 'The head carries the entity');
        $this->assertSame($messages->pluck('id')->all(), $params['burst_message_ids']);
    }

    /**
     * An MMS with no caption has nothing to say. The prompt collapses to empty rather than to a
     * string of blank lines, which is what lets the responder fall back to the head's own body.
     */
    public function testATextlessBurstProducesAnEmptyPromptRatherThanBlankLines(): void
    {
        $app = app(Apps::class);
        $channel = $this->recordingChannel();

        new FlushSmsBurstAction($app, $channel, $this->burst($app, ['', '   ']))->execute();

        $this->assertSame('', $channel->fires[0][1]['burst_text']);
    }

    /**
     * @param list<string> $bodies
     *
     * @return Collection<int, Message>
     */
    private function burst(Apps $app, array $bodies): Collection
    {
        $messages = new Collection();

        foreach ($bodies as $body) {
            $messages->push(Message::factory()->create([
                'apps_id' => $app->getId(),
                'message' => ['content' => $body, 'from_me' => false],
            ]));
        }

        return $messages;
    }

    private function recordingChannel(): InterceptingChannel
    {
        $user = auth()->user();

        return InterceptingChannel::wrapping(Channel::firstOrCreate(
            [
                'apps_id' => app(Apps::class)->getId(),
                'companies_id' => $user->getCurrentCompany()->getId(),
                'slug' => 'twilio-flush-' . fake()->unique()->numerify('##########'),
            ],
            [
                'name' => 'SMS burst flush',
                'description' => 'Records workflow announcements',
                'users_id' => $user->getId(),
            ]
        ));
    }
}
