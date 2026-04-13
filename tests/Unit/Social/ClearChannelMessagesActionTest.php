<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Kanvas\Social\Channels\Actions\ClearChannelMessagesAction;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCaseUnit;

class ClearChannelMessagesActionTest extends TestCaseUnit
{
    use MockeryPHPUnitIntegration;

    public function testItDetachesAllChannelMessagesAndDeletesExclusiveOnes(): void
    {
        $exclusiveMessage = Mockery::mock(Message::class)->makePartial();
        $exclusiveMessage->channels_count = 1;
        $exclusiveMessage->shouldReceive('delete')->once()->andReturn(true);

        $sharedMessage = Mockery::mock(Message::class)->makePartial();
        $sharedMessage->channels_count = 2;
        $sharedMessage->shouldReceive('delete')->never();

        $messagesRelation = Mockery::mock(BelongsToMany::class);
        $messagesRelation->shouldReceive('withCount')->once()->with('channels')->andReturnSelf();
        $messagesRelation->shouldReceive('get')->once()->andReturn(collect([
            $exclusiveMessage,
            $sharedMessage,
        ]));
        $messagesRelation->shouldReceive('detach')->once()->andReturn(2);

        $channel = new class () extends Channel {
            public bool $saveCalled = false;
            public BelongsToMany $messagesRelation;

            public function messages(): BelongsToMany
            {
                return $this->messagesRelation;
            }

            public function saveOrFail(array $options = []): bool
            {
                $this->saveCalled = true;

                return true;
            }
        };

        $channel->messagesRelation = $messagesRelation;
        $channel->last_message_id = 55;

        DB::shouldReceive('connection->transaction')
            ->once()
            ->andReturnUsing(fn (callable $callback): bool => $callback());

        $result = new ClearChannelMessagesAction($channel)->execute();

        $this->assertTrue($result);
        $this->assertTrue($channel->saveCalled);
        $this->assertNull($channel->last_message_id);
    }
}
