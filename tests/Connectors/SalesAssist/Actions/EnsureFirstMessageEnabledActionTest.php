<?php

declare(strict_types=1);

namespace Tests\Connectors\SalesAssist\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\EnsureFirstMessageEnabledAction;
use Kanvas\Connectors\SalesAssist\Exceptions\FirstMessageDisabledException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Tests\TestCase;

final class EnsureFirstMessageEnabledActionTest extends TestCase
{
    use DatabaseTransactions;

    public function testThrowsWhenLeadTypeExplicitlyDisablesFirstMessage(): void
    {
        $lead = $this->makeLead(['internet_first_fu_active_default' => false]);

        $this->expectException(FirstMessageDisabledException::class);
        $this->expectExceptionMessage('internet_first_fu_active_default');

        new EnsureFirstMessageEnabledAction($lead)->execute();
    }

    public function testAllowsWhenLeadTypeEnablesFirstMessage(): void
    {
        $result = new EnsureFirstMessageEnabledAction(
            $this->makeLead(['internet_first_fu_active_default' => true])
        )->execute();

        $this->assertSame('eligible', $result['status']);
        $this->assertTrue($result['configured']);
    }

    public function testAllowsWhenLeadTypeDoesNotConfigureFirstMessage(): void
    {
        $result = new EnsureFirstMessageEnabledAction($this->makeLead([]))->execute();

        $this->assertSame('eligible', $result['status']);
        $this->assertFalse($result['configured']);
    }

    private function makeLead(array $config): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $type = LeadType::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'name' => 'Internet ' . uniqid(),
            ],
            ['description' => 'Internet Lead', 'is_active' => 1],
        );
        $type->config = $config;
        $type->saveOrFail();

        return Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'leads_types_id' => $type->getId(),
        ]);
    }
}
