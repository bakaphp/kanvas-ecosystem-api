<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Notifications;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingAudienceEnum;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingStatusEnum;
use Kanvas\Notifications\Mailings\Models\AppUserMailing;
use Tests\TestCase;

class AppUserMailingsQueryTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    private function mailing(int $appsId, int $companiesId, string $template): AppUserMailing
    {
        $mailing = new AppUserMailing();
        $mailing->apps_id = $appsId;
        $mailing->companies_id = $companiesId;
        $mailing->users_id = auth()->user()->getId();
        $mailing->audience = AppUserMailingAudienceEnum::ACTIVE_USERS;
        $mailing->template_name = $template;
        $mailing->status = AppUserMailingStatusEnum::COMPLETED;
        $mailing->total_eligible = 2;
        $mailing->sent_count = 2;
        $mailing->recipient_ids = [1, 2];
        $mailing->saveOrFail();

        return $mailing;
    }

    public function testTheListIsScopedToTheCurrentAppAndCompanyNewestFirst(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $older = $this->mailing($app->getId(), $company->getId(), 'older-' . uniqid());
        $newer = $this->mailing($app->getId(), $company->getId(), 'newer-' . uniqid());
        $otherCompany = $this->mailing($app->getId(), $company->getId() + 987654, 'foreign-' . uniqid());
        $otherApp = $this->mailing($app->getId() + 987654, $company->getId(), 'foreign-app-' . uniqid());

        $response = $this->graphQL(/** @lang GraphQL */ '
            {
                appUserMailings(first: 50, orderBy: [{ column: ID, order: DESC }]) {
                    data {
                        id
                        template_name
                        status
                        audience
                        sent_count
                        requester { id }
                    }
                }
            }
        ')->assertSuccessful();

        $rows = collect($response->json('data.appUserMailings.data'));
        $ids = $rows->pluck('id')->map(fn (mixed $id): int => (int) $id);

        $this->assertTrue($ids->contains($newer->getId()));
        $this->assertTrue($ids->contains($older->getId()));
        $this->assertFalse($ids->contains($otherCompany->getId()));
        $this->assertFalse($ids->contains($otherApp->getId()));
        $this->assertLessThan(
            $ids->search($older->getId()),
            $ids->search($newer->getId()),
        );
        $row = $rows->firstWhere('id', (string) $newer->getId());
        $this->assertSame('completed', $row['status']);
        $this->assertSame('active_users', $row['audience']);
        $this->assertSame(auth()->user()->getId(), (int) $row['requester']['id']);
    }

    public function testTheFrozenRecipientsCannotBeQueried(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            {
                appUserMailings(first: 1) {
                    data { recipient_ids }
                }
            }
        ')->assertGraphQLErrorMessage('Cannot query field "recipient_ids" on type "AppUserMailing".');
    }
}
