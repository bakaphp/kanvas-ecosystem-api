<?php

declare(strict_types=1);

namespace Tests\Connectors\Zoho;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Workflows\ZohoPullLeadActivity;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use ReflectionClass;
use Tests\TestCase;

final class ZohoPullLeadActivityTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    public function testReturnsLocalLeadWithoutSyncing(): void
    {
        $lead = $this->createLead();
        $lead->set(CustomFieldEnum::ZOHO_LEAD_ID->value, 'zoho-local-123');

        $reflection = new ReflectionClass(ZohoPullLeadActivity::class);
        $activity = $reflection->newInstanceWithoutConstructor();
        $result = $activity->execute($lead, app(Apps::class), [
            'zoho_id' => 'zoho-local-123',
        ]);

        $this->assertSame($lead->getId(), $result['lead']['id']);
        $this->assertFalse($result['synced']);
    }

    public function testPullsAndReturnsLeadWhenNoLocalMatchExists(): void
    {
        $lead = $this->createLead();
        $lead->set('ai_mode', 'manual');
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $activity = new class ($lead) extends ZohoPullLeadActivity {
            public bool $syncRequested = false;

            public function __construct(private Lead $lead)
            {
            }

            protected function syncLeadFromZoho(
                AppInterface $app,
                Companies $company,
                LeadReceiver $receiver,
                string $zohoLeadId
            ): ?Lead {
                $this->syncRequested = $zohoLeadId === 'zoho-remote-456';

                return $this->lead;
            }
        };

        $entity = new Model();
        $entity->companies_id = $company->getId();

        $result = $activity->execute($entity, $app, ['entity_id' => 'zoho-remote-456']);

        $this->assertTrue($activity->syncRequested);
        $this->assertSame($lead->getId(), $result['lead']['id']);
        $this->assertTrue($result['synced']);
    }

    private function createLead(): Lead
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        return Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();
    }
}
