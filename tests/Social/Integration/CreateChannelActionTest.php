<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelData;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * The get-or-create has to be safe to run from several workers at once.
 *
 * `channels` has no unique index on the (app, company, slug, entity) identity, so reading the row with
 * `lockForUpdate()` before it exists locked the *gap* rather than a row — which excludes nothing (gap
 * locks are mutually compatible) and deadlocks the insert that follows against any parallel worker
 * writing into the same range. Plans were the visible casualty: `PlanObserver` swallows the failure, so
 * a plan simply came out with no Activities channel and nothing to post on.
 */
final class CreateChannelActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'social'];

    private Apps $currentApp;
    private Companies $currentCompany;
    private Users $actingUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->actingUser = auth()->user();
        $this->currentCompany = $this->actingUser->getCurrentCompany();
    }

    public function testItNeverLocksRowsToCreateAChannel(): void
    {
        $social = DB::connection('social');
        $social->flushQueryLog();
        $social->enableQueryLog();

        new CreateChannelAction($this->channelData())->execute();

        $statements = array_map(fn (array $entry): string => strtolower($entry['query']), $social->getQueryLog());
        $social->disableQueryLog();

        $this->assertNotEmpty($statements, 'No query ran, so this asserts nothing about locking.');
        $this->assertEmpty(
            array_filter($statements, fn (string $sql): bool => str_contains($sql, 'for update')),
            'A locking read of a channel that does not exist yet gap-locks the range and deadlocks parallel inserts.'
        );
    }

    public function testItReturnsTheSameChannelInsteadOfASecondOne(): void
    {
        $data = $this->channelData();

        $first = new CreateChannelAction($data)->execute();
        $second = new CreateChannelAction($data)->execute();

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame(
            1,
            Channel::where('apps_id', $this->currentApp->getId())
                ->where('slug', $data->slug)
                ->count()
        );
    }

    private function channelData(): ChannelData
    {
        return new ChannelData(
            apps: $this->currentApp,
            companies: $this->currentCompany,
            users: $this->actingUser,
            entity_id: (string) fake()->unique()->randomNumber(8),
            entity_namespace: Users::class,
            name: ChannelNameEnum::ACTIVITIES->value,
            description: 'Concurrency regression',
            slug: (string) fake()->unique()->uuid(),
        );
    }
}
