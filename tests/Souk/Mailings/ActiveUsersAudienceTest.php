<?php

declare(strict_types=1);

namespace Tests\Souk\Mailings;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingAudienceEnum;
use Kanvas\Notifications\Mailings\Services\MailingAudienceService;
use Kanvas\Souk\Mailings\Audiences\ActiveUsersAudience;
use Kanvas\Souk\Orders\Services\OrderReportService;
use Tests\TestCase;
use Tests\Traits\MakesActiveUsers;

class ActiveUsersAudienceTest extends TestCase
{
    use DatabaseTransactions;
    use MakesActiveUsers;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'commerce', 'crm'];

    public function testTheAudienceIsTheSameSetTheActiveUsersReportCounts(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $type = $this->makeOrderType($company);
        $first = $this->makeUserWhoPaid($type);
        $second = $this->makeUserWhoPaid($type);
        $this->payFor($type, $first);
        $notPaid = $this->makeUserWithoutPaidPayment($type);

        $service = new OrderReportService($app, $company);
        $ids = $service
            ->activeUserIdsQuery(
                orderTypeNames: [$type->name],
                since: null,
                until: null,
                timezone: 'UTC',
            )
            ->pluck('users_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
        $report = $service->activeUsers(
            orderTypeNames: [$type->name],
            since: null,
            until: null,
            timezone: 'UTC',
            groupBy: null,
        );
        $resolved = new ActiveUsersAudience()->resolve($app, $company, ['order_types' => [$type->name], 'timezone' => 'UTC']);
        sort($resolved);

        $this->assertSame([$first->getId(), $second->getId()], $ids);
        $this->assertNotContains($notPaid->getId(), $ids);
        $this->assertSame($report['total_active_users'], count($ids));
        $this->assertSame($ids, $resolved);
    }

    public function testSinceAndUntilCutThePaymentDays(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $type = $this->makeOrderType($company);
        $payer = $this->makeUserWhoPaid($type);
        $audience = new ActiveUsersAudience();
        $criteria = ['order_types' => [$type->name], 'timezone' => 'UTC'];

        $inside = $audience->resolve($app, $company, [...$criteria, 'since' => '2026-02-10', 'until' => '2026-02-10']);
        $tooLate = $audience->resolve($app, $company, [...$criteria, 'since' => '2026-02-11']);
        $tooEarly = $audience->resolve($app, $company, [...$criteria, 'until' => '2026-02-09']);

        $this->assertSame([$payer->getId()], $inside);
        $this->assertSame([], $tooLate);
        $this->assertSame([], $tooEarly);
    }

    public function testTheActiveUsersAudienceNeedsACompany(): void
    {
        $this->expectException(ValidationException::class);

        new ActiveUsersAudience()->resolve(app(Apps::class), null, []);
    }

    public function testTheConfiguredAudienceIsResolvedAndFilteredByEligibility(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $type = $this->makeOrderType($company);
        $eligible = $this->makeUserWhoPaid($type);
        $this->makeUserWhoPaid($type, ['banned' => 1]);

        $audience = new MailingAudienceService($app, $company)->resolve(
            AppUserMailingAudienceEnum::ACTIVE_USERS,
            ['order_types' => [$type->name], 'timezone' => 'UTC'],
        );

        $this->assertSame(2, $audience['total']);
        $this->assertSame([$eligible->getId()], $audience['eligible']);
        $this->assertSame(1, $audience['excluded']['banned']);
    }
}
