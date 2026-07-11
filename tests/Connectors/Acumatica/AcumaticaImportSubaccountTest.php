<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportSubaccount;
use Tests\TestCase;

class AcumaticaImportSubaccountTest extends TestCase
{
    public function testMapsCoreFields(): void
    {
        $sub = AcumaticaImportSubaccount::from([
            'SubID' => '402222',
            'SubCD' => 'MKT-CA-000',
            'Description' => 'Marketing — California',
            'Active' => 1,
        ]);

        $this->assertSame('402222', $sub->externalId);
        $this->assertSame('MKT-CA-000', $sub->subCode);
        $this->assertSame('Marketing — California', $sub->description);
        $this->assertTrue($sub->isActive);
    }

    public function testInactiveAndBlankDescription(): void
    {
        $sub = AcumaticaImportSubaccount::from([
            'SubID' => '999999',
            'SubCD' => '000000',
            'Description' => '',
            'Active' => 0,
        ]);

        $this->assertSame('000000', $sub->subCode);
        $this->assertNull($sub->description);
        $this->assertFalse($sub->isActive);
    }
}
