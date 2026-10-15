<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Users;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Observers\UsersAssociatedAppsObserver;
use Tests\TestCase;

/**
 * The observer is queued, and locally the queue is not sync, so it is invoked directly.
 */
final class UsersAssociatedAppsObserverTest extends TestCase
{
    use DatabaseTransactions;

    public function testACounterBumpDoesNotRewriteTheAppUserTotal(): void
    {
        $profile = auth()->user()->getAppProfile(app(Apps::class));
        $profile->increment('unread_notifications_count');

        $this->assertSame(0, $this->appSettingsWritesDuring($profile));
    }

    public function testAMembershipChangeRecountsTheAppUsers(): void
    {
        $profile = auth()->user()->getAppProfile(app(Apps::class));
        $profile->is_deleted = 1;
        $profile->save();

        $this->assertSame(1, $this->appSettingsWritesDuring($profile));
    }

    private function appSettingsWritesDuring(UsersAssociatedApps $profile): int
    {
        $writes = 0;

        DB::listen(function (QueryExecuted $query) use (&$writes) {
            if (str_starts_with($query->sql, 'insert into `apps_settings`')) {
                $writes++;
            }
        });

        new UsersAssociatedAppsObserver()->updated($profile);

        return $writes;
    }
}
