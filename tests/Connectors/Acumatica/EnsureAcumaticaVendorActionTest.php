<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Kanvas\Connectors\Acumatica\Actions\EnsureAcumaticaVendorAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Mockery;
use Tests\Scribe\ScribeTestCase;

class EnsureAcumaticaVendorActionTest extends ScribeTestCase
{
    public function test_returns_existing_code_without_touching_acumatica(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $vendor->set(CustomFieldEnum::VENDOR_ID->value, 'V0001');

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldNotReceive('findOrCreate');

        $code = new EnsureAcumaticaVendorAction($vendor, writer: $writer)->execute();

        $this->assertSame('V0001', $code);
    }

    public function test_find_or_creates_vendor_and_caches_code_on_the_org(): void
    {
        $vendor = $this->seedTestOrganization('New Vendor LLC');

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('findOrCreate')->once()
            ->with('Vendor', Mockery::type('array'), Mockery::type('array'))
            ->andReturn(['id' => 'GUID-NEW', 'VendorID' => ['value' => 'V4242']]);

        $code = new EnsureAcumaticaVendorAction(
            $vendor,
            taxId: '130-99999-9',
            name: 'New Vendor LLC',
            writer: $writer,
        )->execute();

        $this->assertSame('V4242', $code);
        $this->assertSame('V4242', (string) $vendor->fresh()->get(CustomFieldEnum::VENDOR_ID->value));
    }
}
