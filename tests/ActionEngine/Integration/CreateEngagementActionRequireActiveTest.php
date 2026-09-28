<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;
use Tests\Traits\BuildsSalesAppFixtures;

final class CreateEngagementActionRequireActiveTest extends TestCase
{
    use BuildsSalesAppFixtures;

    public function testRejectsAnInactiveSalesAppWhenRequired(): void
    {
        $action = $this->makeSalesAppAction('Add Trade');
        $this->makeSalesApp($action, auth()->user()->getCurrentCompany(), isActive: false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The {$action->slug} Sales App is not available for this company.");

        $this->resolve($action->slug, requireActiveAction: true);
    }

    public function testRejectsAnUnknownSalesAppWhenRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->resolve('missing-sales-app-' . Str::lower(Str::random(8)), requireActiveAction: true);
    }

    public function testAcceptsAnActiveSalesAppWhenRequired(): void
    {
        $action = $this->makeSalesAppAction('Credit App');
        $companyAction = $this->makeSalesApp($action, auth()->user()->getCurrentCompany(), isActive: true);

        $this->assertSame($companyAction->getId(), $this->resolve($action->slug, requireActiveAction: true));
    }

    public function testKeepsResolvingInactiveSalesAppsForExistingCallers(): void
    {
        $action = $this->makeSalesAppAction('Share Vehicle');
        $companyAction = $this->makeSalesApp($action, auth()->user()->getCurrentCompany(), isActive: false);

        $this->assertSame($companyAction->getId(), $this->resolve($action->slug, requireActiveAction: false));
    }

    private function resolve(string $slug, bool $requireActiveAction): int
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $engagementData = new EngagementData(
            app: $app,
            company: $company,
            user: $user,
            lead: $lead,
            action: $slug,
            requestId: (string) Str::uuid(),
            source: 'agent',
            status: ActionStatusEnum::OPEN,
        );

        // Receiver lookup needs wiring this test doesn't own; the OPEN status above likewise skips link generation.
        $createEngagement = new class (
            engagementData: $engagementData,
            requireActiveAction: $requireActiveAction
        ) extends CreateEngagementAction {
            protected function prepareData(): void
            {
            }

            public function resolvedCompanyActionId(): int
            {
                return $this->companyAction->getId();
            }
        };

        return $createEngagement->resolvedCompanyActionId();
    }
}
