<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Kanvas\Connectors\Acumatica\Actions\EnsureAcumaticaCustomerAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Guild\Customers\Models\People;
use Mockery;
use Tests\Scribe\ScribeTestCase;

class EnsureAcumaticaCustomerActionTest extends ScribeTestCase
{
    private function person(): People
    {
        return People::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['firstname' => 'Jane', 'lastname' => 'Buyer']);
    }

    public function test_returns_existing_code_without_touching_acumatica(): void
    {
        $customer = $this->person();
        $customer->set(CustomFieldEnum::CUSTOMER_ID->value, 'C0001');

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldNotReceive('findOrCreate');

        $code = new EnsureAcumaticaCustomerAction($this->kanvasApp, $customer, writer: $writer)->execute();

        $this->assertSame('C0001', $code);
    }

    public function test_find_or_creates_customer_and_caches_code_on_the_person(): void
    {
        $customer = $this->person();

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('findOrCreate')->once()
            ->with('Customer', Mockery::type('array'), Mockery::type('array'))
            ->andReturn(['id' => 'GUID-C', 'CustomerID' => ['value' => 'C4242']]);

        $code = new EnsureAcumaticaCustomerAction(
            $this->kanvasApp,
            $customer,
            name: 'Jane Buyer',
            email: 'jane@buyer.test',
            writer: $writer,
        )->execute();

        $this->assertSame('C4242', $code);
        $this->assertSame('C4242', (string) $customer->fresh()->get(CustomFieldEnum::CUSTOMER_ID->value));
    }
}
