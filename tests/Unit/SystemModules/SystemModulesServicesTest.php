<?php

declare(strict_types=1);

namespace Tests\Unit\SystemModules;

use Illuminate\Validation\ValidationException;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\SystemModules\Services\SystemModulesServices;
use Tests\TestCaseUnit;

class SystemModulesServicesTest extends TestCaseUnit
{
    public function testMappingWithoutHandlerPassesWhenModuleFieldsAreMapped(): void
    {
        SystemModulesServices::validateFields(
            $this->moduleRequiring(['product_name', 'sku']),
            ['product_name' => 'Name', 'sku' => 'SKU']
        );

        $this->addToAssertionCount(1);
    }

    public function testMissingModuleRequiredFieldIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/sku/');

        SystemModulesServices::validateFields(
            $this->moduleRequiring(['product_name', 'sku']),
            ['product_name' => 'Name']
        );
    }

    /**
     * @param array<int, string> $required
     */
    private function moduleRequiring(array $required): SystemModules
    {
        $module = new SystemModules();
        $module->fields = array_fill_keys($required, ['required' => true]) + [
            'product_description' => ['required' => false],
        ];

        return $module;
    }
}
