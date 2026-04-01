<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Console\Commands\Inventory\InventoryExportProductsCommand;
use Tests\TestCaseUnit;

class InventoryExportProductsCommandTest extends TestCaseUnit
{
    public function testCommandSignatureIncludesEmailTemplateAndSubjectOptions(): void
    {
        $command = new InventoryExportProductsCommand();

        $this->assertSame('kanvas-inventory:export-products', $command->getName());
        $this->assertTrue($command->getDefinition()->hasArgument('app_id'));
        $this->assertTrue($command->getDefinition()->hasArgument('company_id'));
        $this->assertTrue($command->getDefinition()->hasOption('email'));
        $this->assertTrue($command->getDefinition()->getOption('email')->isArray());
        $this->assertTrue($command->getDefinition()->hasOption('template'));
        $this->assertSame(
            'inventory-products-export',
            $command->getDefinition()->getOption('template')->getDefault()
        );
        $this->assertTrue($command->getDefinition()->hasOption('subject'));
    }
}
