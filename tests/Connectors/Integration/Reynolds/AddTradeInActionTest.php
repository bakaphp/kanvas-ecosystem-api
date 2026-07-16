<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Reynolds;

use Kanvas\Connectors\Reynolds\Actions\AddTradeInAction;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class AddTradeInActionTest extends TestCase
{
    public function testStoresSubmittedFormAndImporterBannerOnLead(): void
    {
        $lead = $this->buildFreshLead();

        $form = [
            'year' => 2021,
            'make' => 'Chevrolet',
            'model' => 'Malibu',
            'trim' => 'LT',
            'vin' => '1G1ZD5ST0MF012345',
            'mileage' => '42,000',
            'int_color' => 'Black',
            'ext_color' => 'Silver',
        ];

        $result = new AddTradeInAction($lead)->execute([
            'verb' => 'add-trade',
            'status' => 'submitted',
            'data' => ['form' => $form],
        ]);

        $lead->refresh();

        $this->assertSame($form, $lead->get(CustomFieldEnum::TRADE_IN_DATA->value));
        $this->assertSame($form, $result['tradein_data']);

        $importer = $lead->get(CustomFieldEnum::TRADE_IN_IMPORTED->value);
        $this->assertSame(1, $importer['active']);
        $this->assertSame('Trade-In Ready to be imported into Reynolds.', $importer['message']);
        $this->assertArrayHasKey('date', $importer);
        $this->assertSame($importer, $result['tradein_imported']);
    }

    public function testStoresEmptyFormWhenMessageHasNoForm(): void
    {
        $lead = $this->buildFreshLead();

        $result = new AddTradeInAction($lead)->execute([
            'verb' => 'add-trade',
            'status' => 'submitted',
            'data' => [],
        ]);

        $lead->refresh();

        $this->assertSame([], $lead->get(CustomFieldEnum::TRADE_IN_DATA->value));
        $this->assertSame([], $result['tradein_data']);
        $this->assertSame(1, $lead->get(CustomFieldEnum::TRADE_IN_IMPORTED->value)['active']);
    }

    private function buildFreshLead(): Lead
    {
        $user = auth()->user();
        $app = app(\Kanvas\Apps\Models\Apps::class);
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        return Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();
    }
}
