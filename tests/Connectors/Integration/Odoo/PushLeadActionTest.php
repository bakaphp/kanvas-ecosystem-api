<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Odoo;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Odoo\Actions\PushLeadAction;
use Kanvas\Connectors\Odoo\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\Connectors\Traits\HasOdooConfiguration;
use Tests\TestCase;

final class PushLeadActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasOdooConfiguration;

    public function testCreatesLeadWhenNoExternalIdExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureOdoo($company, $app);

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $this->fakeOdooApi([
            'create:crm.lead' => 701,
        ]);

        $result = new PushLeadAction($lead)->execute();

        $this->assertSame(701, $result['id']);
        $this->assertSame('701', (string) $lead->get(CustomFieldEnum::ODOO_LEAD_ID->value));
        $this->assertSame('lead', $result['type']);
    }

    public function testUpdatesLeadWhenExternalIdAlreadyExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureOdoo($company, $app);

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();
        $lead->set(CustomFieldEnum::ODOO_LEAD_ID->value, '701');

        $this->fakeOdooApi([
            'search_read:crm.lead' => [['id' => 701]],
            'write:crm.lead' => true,
        ]);

        $result = new PushLeadAction($lead)->execute();

        $this->assertSame('701', (string) $result['id']);
    }
}
