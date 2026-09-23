<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Imports\DataTransferObject\ImportTemplate;
use Kanvas\Imports\Enums\ImportTemplateEnum;
use Tests\TestCaseUnit;

class ImportTemplateTest extends TestCaseUnit
{
    public function testEveryShippedTemplateLoadsWithAValidDefaultOptionSet(): void
    {
        foreach (ImportTemplateEnum::cases() as $case) {
            $template = $case->template();

            $this->assertSame($case->value, $template->key);
            $this->assertNotEmpty($template->mapping);
            $this->assertIsArray($template->mappingFor($template->resolveOptions([])));
            $this->assertEmpty(
                array_diff($template->requiredColumns, $template->fileHeader),
                'Required columns must also be listed in file_header'
            );
        }
    }

    public function testResolveOptionsFillsDefaultsAndRejectsUnknownValues(): void
    {
        $template = $this->template();

        $this->assertSame(['price_source' => 'msrp_first'], $template->resolveOptions([]));
        $this->assertSame(['price_source' => 'price_first'], $template->resolveOptions(['price_source' => 'price_first']));

        $this->expectException(ValidationException::class);
        $template->resolveOptions(['price_source' => 'cheapest']);
    }

    public function testResolveOptionsRejectsAnUnknownOptionName(): void
    {
        $this->expectException(ValidationException::class);
        $this->template()->resolveOptions(['currency' => 'usd']);
    }

    public function testMappingForAppliesTheChosenPatchesWithoutTouchingTheDefinition(): void
    {
        $template = $this->template();

        $mapping = $template->mappingFor($template->resolveOptions(['price_source' => 'price_first']));

        $this->assertSame(['$coalesce' => ['Price', 'MSRP']], $mapping['price']);
        $this->assertSame(['$coalesce' => ['Price', 'MSRP']], $mapping['warehouses'][0]['price']);
        $this->assertSame('VIN', $mapping['warehouses'][0]['sku']);
        $this->assertSame(['$coalesce' => ['MSRP', 'Price']], $template->mapping['price']);
    }

    public function testSignatureAndNameChangeOnlyWithTheOptions(): void
    {
        $template = $this->template();
        $default = $template->resolveOptions([]);
        $priceFirst = $template->resolveOptions(['price_source' => 'price_first']);

        $this->assertSame($template->signature($default), $template->signature($template->resolveOptions([])));
        $this->assertNotSame($template->signature($default), $template->signature($priceFirst));
        $this->assertSame('Test template', $template->mapperName($default));
        $this->assertSame('Test template · price_source=price_first', $template->mapperName($priceFirst));
    }

    public function testANewVersionGetsItsOwnMapperName(): void
    {
        // CreateFilesystemMapperAction's firstOrCreate keys on the name, so two versions sharing one
        // name means a bumped template silently hands back the old version's mapper and mapping.
        $v2 = ImportTemplate::fromDefinition([...$this->definition(), 'version' => 2]);

        $this->assertSame('Test template v2', $v2->mapperName($v2->resolveOptions([])));
        $this->assertSame(
            'Test template v2 · price_source=price_first',
            $v2->mapperName($v2->resolveOptions(['price_source' => 'price_first']))
        );
        $this->assertNotSame(
            $this->template()->mapperName($this->template()->resolveOptions([])),
            $v2->mapperName($v2->resolveOptions([]))
        );
    }

    public function testCompareHeaderSplitsMissingRequiredOptionalAndExtraIgnoringCase(): void
    {
        $result = $this->template()->compareHeader([' vin ', 'Price', 'Series', 'Mileage']);

        $this->assertFalse($result['compatible']);
        $this->assertSame(['MSRP'], $result['missing_required']);
        $this->assertSame(['Photo Url List'], $result['missing_optional']);
        $this->assertSame(['Mileage'], $result['extra']);
    }

    public function testCompareHeaderOfFileReadsTheCsvHeader(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.csv';
        file_put_contents($path, "VIN,MSRP,Price,Series,Photo Url List\n1HGCM,1,2,LX,\n");

        try {
            $result = $this->template()->compareHeaderOfFile($path);
        } finally {
            @unlink($path);
        }

        $this->assertTrue($result['compatible']);
        $this->assertSame([], $result['missing_optional']);
        $this->assertSame([], $result['extra']);
    }

    private function template(): ImportTemplate
    {
        return ImportTemplate::fromDefinition($this->definition());
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(): array
    {
        return [
            'key' => 'test_template',
            'version' => 1,
            'name' => 'Test template',
            'system_module' => 'App\\Model',
            'required_columns' => ['VIN', 'MSRP', 'Price'],
            'file_header' => ['VIN', 'MSRP', 'Price', 'Series', 'Photo Url List'],
            'mapping' => [
                'sku' => 'VIN',
                'price' => ['$coalesce' => ['MSRP', 'Price']],
                'warehouses' => [['sku' => 'VIN', 'price' => ['$coalesce' => ['MSRP', 'Price']]]],
            ],
            'options' => [
                'price_source' => [
                    'default' => 'msrp_first',
                    'choices' => [
                        'msrp_first' => [],
                        'price_first' => [
                            'mapping.price' => ['$coalesce' => ['Price', 'MSRP']],
                            'mapping.warehouses.0.price' => ['$coalesce' => ['Price', 'MSRP']],
                        ],
                    ],
                ],
            ],
        ];
    }
}
