<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\PasoRapido;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Activities\Models\Activity;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PasoRapido\Enums\TagVerificationBlockReasonEnum;
use Kanvas\Connectors\PasoRapido\Neuron\Tools\ReviewTagVerificationBlocksTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class ReviewTagVerificationBlocksToolTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2020-06-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testNonAdminIsDenied(): void
    {
        $app = app(Apps::class);
        $company = $this->appCompany($app);

        $result = new ReviewTagVerificationBlocksTool()
            ->withContext($app, $company, auth()->user())
            ->forRequestingUser(Users::factory()->create())
            ->__invoke();

        $this->assertFalse($result['success']);
        $this->assertSame('denied', $result['outcome']);
    }

    public function testNonAppCompanyIsDenied(): void
    {
        $app = app(Apps::class);
        $otherCompany = Companies::factory()->create(['users_id' => auth()->user()->getId()]);

        $result = new ReviewTagVerificationBlocksTool()
            ->withContext($app, $otherCompany, auth()->user())
            ->__invoke();

        $this->assertFalse($result['success']);
        $this->assertSame('denied', $result['outcome']);
    }

    public function testAllowedWithNoRequestingHumanReturnsByUserCases(): void
    {
        $app = app(Apps::class);
        $company = $this->appCompany($app);
        $user = $this->createUser();

        $this->seedBlock($app, $user->getId());

        $result = new ReviewTagVerificationBlocksTool()
            ->withContext($app, $company, auth()->user())
            ->__invoke(since: now()->subDay()->toDateString(), until: now()->addDay()->toDateString());

        $this->assertTrue($result['success']);
        $this->assertSame('ok', $result['outcome']);
        $this->assertCount(1, $result['by_user']);
        $this->assertSame($user->getId(), $result['by_user'][0]['user']['id']);
        $this->assertFalse($result['by_user'][0]['user']['still_banned']);
        $this->assertFalse($result['by_user'][0]['is_corporate']);
        $this->assertCount(1, $result['by_ip']);
        $this->assertSame('1.1.1.1', $result['by_ip'][0]['ip']);
        $this->assertSame(0, $result['success_count']);
    }

    public function testEmptyRangeReturnsNoop(): void
    {
        $app = app(Apps::class);
        $company = $this->appCompany($app);

        $result = new ReviewTagVerificationBlocksTool()
            ->withContext($app, $company, auth()->user())
            ->__invoke(since: '2000-01-01', until: '2000-01-01');

        $this->assertTrue($result['success']);
        $this->assertSame('noop', $result['outcome']);
        $this->assertSame([], $result['by_user']);
        $this->assertSame([], $result['by_ip']);
    }

    public function testUntilBeforeSinceIsInvalidArgs(): void
    {
        $app = app(Apps::class);
        $company = $this->appCompany($app);

        $result = new ReviewTagVerificationBlocksTool()
            ->withContext($app, $company, auth()->user())
            ->__invoke(since: '2026-01-10', until: '2026-01-01');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
    }

    public function testBadDateStringIsInvalidArgs(): void
    {
        $app = app(Apps::class);
        $company = $this->appCompany($app);

        $result = new ReviewTagVerificationBlocksTool()
            ->withContext($app, $company, auth()->user())
            ->__invoke(since: 'not-a-date');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
    }

    public function testSpanOverThirtyOneDaysIsInvalidArgs(): void
    {
        $app = app(Apps::class);
        $company = $this->appCompany($app);

        $result = new ReviewTagVerificationBlocksTool()
            ->withContext($app, $company, auth()->user())
            ->__invoke(since: '2026-01-01', until: '2026-03-01');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
    }

    public function testSecondIdenticalCallInTheSameTurnReturnsTheGuardedRepeat(): void
    {
        $app = app(Apps::class);
        $company = $this->appCompany($app);
        $user = $this->createUser();

        $this->seedBlock($app, $user->getId());

        $registered = new ReviewTagVerificationBlocksTool()->withContext($app, $company, auth()->user());
        $since = now()->subDay()->toDateString();
        $until = now()->addDay()->toDateString();

        $first = (clone $registered)->__invoke(since: $since, until: $until);
        $second = (clone $registered)->__invoke(since: $since, until: $until);

        $this->assertArrayNotHasKey('repeat_call', $first);
        $this->assertTrue($second['repeat_call']);
        $this->assertSame($first['by_user'], $second['by_user']);
    }

    private function appCompany(Apps $app): Companies
    {
        return $app->getAppCompany();
    }

    private function seedBlock(Apps $app, int $userId): void
    {
        $log = new Activity();
        $log->log_name = 'paso-rapido-verify-' . $app->getId();
        $log->description = 'PasoRapido tag verification blocked';
        $log->causer_type = Users::class;
        $log->causer_id = $userId;
        $log->properties = [
            'tag' => '941001',
            'app_id' => $app->getId(),
            'ip' => '1.1.1.1',
            'reason' => TagVerificationBlockReasonEnum::USER_MAX_DAILY->value,
            'is_corporate' => false,
        ];
        $log->created_at = now();
        $log->updated_at = now();
        $log->saveOrFail();
    }
}
