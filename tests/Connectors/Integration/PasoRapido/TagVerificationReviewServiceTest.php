<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\PasoRapido;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Activities\Models\Activity;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PasoRapido\Enums\TagVerificationBlockReasonEnum;
use Kanvas\Connectors\PasoRapido\Services\TagVerificationReviewService;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Tests\TestCase;

final class TagVerificationReviewServiceTest extends TestCase
{
    use DatabaseTransactions;

    private const BLOCKED_DESCRIPTION = 'PasoRapido tag verification blocked';

    private const SUCCESS_DESCRIPTION = 'PasoRapido tag verification';

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);

        Carbon::setTestNow(Carbon::parse('2020-06-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testByUserGroupsReasonCountsAndDistinctTagsAndIps(): void
    {
        $user = $this->createUser();

        $this->blockedLog(
            $user->getId(),
            '1.1.1.1',
            TagVerificationBlockReasonEnum::USER_MAX_DAILY,
            '941001',
        );
        $this->blockedLog(
            $user->getId(),
            '1.1.1.1',
            TagVerificationBlockReasonEnum::USER_MAX_DAILY,
            '941002',
        );
        $this->blockedLog(
            $user->getId(),
            '2.2.2.2',
            TagVerificationBlockReasonEnum::SEQUENTIAL_SCAN,
            '941003',
        );

        $rows = $this->service()->byUser(now()->subDay(), now()->addDay(), 20);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $profile = $this->profile($user);
        $this->assertSame($user->getId(), $row['user']['id']);
        $this->assertSame($profile->firstname . ' ' . $profile->lastname, $row['user']['name']);
        $this->assertSame($profile->email, $row['user']['email']);
        $this->assertFalse($row['user']['still_banned']);
        $this->assertSame(3, $row['total']);
        $this->assertSame(['user_max_daily' => 2, 'sequential_scan' => 1], $row['reasons']);
        $this->assertSame(3, $row['distinct_tags']);
        $this->assertSame(2, $row['distinct_ips']);
        $this->assertFalse($row['is_corporate']);
    }

    public function testIsCorporateIsTrueWhenAnyBlockedRowInRangeHasIt(): void
    {
        $user = $this->createUser();

        $this->blockedLog(
            $user->getId(),
            '1.1.1.1',
            TagVerificationBlockReasonEnum::USER_MAX_DAILY,
            '941001',
        );
        $this->blockedLog(
            $user->getId(),
            '1.1.1.1',
            TagVerificationBlockReasonEnum::SEQUENTIAL_SCAN,
            '941002',
            isCorporate: true,
        );

        $rows = $this->service()->byUser(now()->subDay(), now()->addDay(), 20);

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['is_corporate']);
    }

    public function testNullTagIsNotCountedAsADistinctTag(): void
    {
        $user = $this->createUser();

        $this->blockedLog(
            $user->getId(),
            '1.1.1.1',
            TagVerificationBlockReasonEnum::UNVERIFIED_ACCOUNT,
            null,
        );
        $this->blockedLog(
            $user->getId(),
            '1.1.1.1',
            TagVerificationBlockReasonEnum::USER_MAX_DAILY,
            '941001',
        );

        $rows = $this->service()->byUser(now()->subDay(), now()->addDay(), 20);

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['total']);
        $this->assertSame(1, $rows[0]['distinct_tags']);
    }

    public function testByIpGroupsAcrossUsersIncludingIpOnlyRows(): void
    {
        $user = $this->createUser();

        $this->blockedLog(
            $user->getId(),
            '9.9.9.9',
            TagVerificationBlockReasonEnum::IP_MAX_DAILY,
            '941001',
        );
        $this->blockedLog(
            null,
            '9.9.9.9',
            TagVerificationBlockReasonEnum::IP_AUTO_BLOCKED,
            null,
        );

        $rows = $this->service()->byIp(now()->subDay(), now()->addDay(), 20);

        $this->assertCount(1, $rows);
        $this->assertSame('9.9.9.9', $rows[0]['ip']);
        $this->assertSame(2, $rows[0]['total']);
        $this->assertSame(1, $rows[0]['distinct_users']);
        $this->assertSame(['ip_max_daily' => 1, 'ip_auto_blocked' => 1], $rows[0]['reasons']);
    }

    public function testIpOnlyBlockedRowAppearsOnlyInByIp(): void
    {
        $this->blockedLog(
            null,
            '8.8.8.8',
            TagVerificationBlockReasonEnum::IP_AUTO_BLOCKED,
            null,
        );

        $this->assertSame([], $this->service()->byUser(now()->subDay(), now()->addDay(), 20));
        $this->assertCount(1, $this->service()->byIp(now()->subDay(), now()->addDay(), 20));
    }

    public function testSuccessCountCountsOnlySuccessRowsInRange(): void
    {
        $user = $this->createUser();

        $this->successLog($user->getId(), '1.1.1.1', '941001');
        $this->successLog($user->getId(), '1.1.1.1', '941002');
        $this->blockedLog(
            $user->getId(),
            '1.1.1.1',
            TagVerificationBlockReasonEnum::USER_MAX_DAILY,
            '941003',
        );

        $this->assertSame(2, $this->service()->successCount(now()->subDay(), now()->addDay()));
    }

    public function testOutOfRangeRowsAreExcluded(): void
    {
        $user = $this->createUser();

        $this->blockedLog(
            $user->getId(),
            '1.1.1.1',
            TagVerificationBlockReasonEnum::USER_MAX_DAILY,
            '941001',
            now()->subDays(10),
        );

        $this->assertSame([], $this->service()->byUser(now()->subDay(), now()->addDay(), 20));
        $this->assertSame([], $this->service()->byIp(now()->subDay(), now()->addDay(), 20));
    }

    public function testOtherAppLogNameRowsAreExcluded(): void
    {
        $user = $this->createUser();

        $this->blockedLog(
            $user->getId(),
            '1.1.1.1',
            TagVerificationBlockReasonEnum::USER_MAX_DAILY,
            '941001',
            appId: $this->kanvasApp->getId() + 999,
        );

        $this->assertSame([], $this->service()->byUser(now()->subDay(), now()->addDay(), 20));
    }

    public function testLimitIsRespectedOnBothLists(): void
    {
        foreach (range(1, 3) as $i) {
            $user = $this->createUser();
            $this->blockedLog(
                $user->getId(),
                "10.0.0.{$i}",
                TagVerificationBlockReasonEnum::USER_MAX_DAILY,
                "94100{$i}",
            );
        }

        $this->assertCount(2, $this->service()->byUser(now()->subDay(), now()->addDay(), 2));
        $this->assertCount(2, $this->service()->byIp(now()->subDay(), now()->addDay(), 2));
    }

    private function service(): TagVerificationReviewService
    {
        return new TagVerificationReviewService($this->kanvasApp);
    }

    private function profile(Users $user): UsersAssociatedApps
    {
        return $user->getAppProfile($this->kanvasApp);
    }

    private function blockedLog(
        ?int $userId,
        string $ip,
        TagVerificationBlockReasonEnum $reason,
        ?string $tag,
        ?Carbon $createdAt = null,
        ?int $appId = null,
        bool $isCorporate = false,
    ): Activity {
        $appId ??= $this->kanvasApp->getId();

        $log = new Activity();
        $log->log_name = 'paso-rapido-verify-' . $appId;
        $log->description = self::BLOCKED_DESCRIPTION;
        $log->causer_type = $userId !== null ? Users::class : null;
        $log->causer_id = $userId;
        $log->properties = [
            'tag' => $tag,
            'app_id' => $appId,
            'ip' => $ip,
            'reason' => $reason->value,
            'is_corporate' => $isCorporate,
        ];
        $log->created_at = $createdAt ?? now();
        $log->updated_at = now();
        $log->saveOrFail();

        return $log;
    }

    private function successLog(
        int $userId,
        string $ip,
        string $tag,
        ?Carbon $createdAt = null,
    ): Activity {
        $log = new Activity();
        $log->log_name = 'paso-rapido-verify-' . $this->kanvasApp->getId();
        $log->description = self::SUCCESS_DESCRIPTION;
        $log->causer_type = Users::class;
        $log->causer_id = $userId;
        $log->properties = [
            'tag' => $tag,
            'app_id' => $this->kanvasApp->getId(),
            'ip' => $ip,
        ];
        $log->created_at = $createdAt ?? now();
        $log->updated_at = now();
        $log->saveOrFail();

        return $log;
    }
}
