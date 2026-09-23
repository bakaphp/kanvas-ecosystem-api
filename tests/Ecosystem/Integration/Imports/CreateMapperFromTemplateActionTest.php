<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Imports;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Imports\Actions\CreateMapperFromTemplateAction;
use Kanvas\Imports\DataTransferObject\MapperFromTemplate;
use Kanvas\Imports\Enums\ImportTemplateEnum;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class CreateMapperFromTemplateActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'inventory'];

    public function testCreatesACompanyMapperWithTheTemplateMappingAndProductType(): void
    {
        $mapper = $this->apply();
        $template = ImportTemplateEnum::DEALER_VEHICLE_CSV->template();

        $this->assertTrue($mapper->wasRecentlyCreated);
        $this->assertSame('Dealer inventory (vehicle CSV)', $mapper->name);
        $this->assertSame($template->mapping, $mapper->mapping);
        $this->assertSame(
            'dealer_vehicle_csv@' . $template->version . '?price_source=msrp_first',
            $mapper->configuration['template']['signature']
        );

        $productType = ProductsTypes::find($mapper->configuration['product_type_id']);
        $this->assertSame('Vehicle', $productType->name);
        $this->assertSame($this->user()->getCurrentCompany()->getId(), (int) $productType->companies_id);
    }

    public function testApplyingTheSameTemplateAndOptionsTwiceReusesTheMapper(): void
    {
        $first = $this->apply();
        $second = $this->apply();

        $this->assertSame($first->getId(), $second->getId());
        $this->assertFalse($second->wasRecentlyCreated);
    }

    public function testDifferentOptionsCreateASeparateMapper(): void
    {
        $default = $this->apply();
        $priceFirst = $this->apply(['price_source' => 'price_first']);

        $this->assertNotSame($default->getId(), $priceFirst->getId());
        $this->assertSame('Dealer inventory (vehicle CSV) · price_source=price_first', $priceFirst->name);
        $this->assertSame(['$coalesce' => ['Price', 'MSRP']], $priceFirst->mapping['price']);
        $this->assertSame($default->configuration['product_type_id'], $priceFirst->configuration['product_type_id']);
    }

    private function apply(array $options = []): FilesystemMapper
    {
        return new CreateMapperFromTemplateAction(
            new MapperFromTemplate(
                template: ImportTemplateEnum::DEALER_VEHICLE_CSV->template(),
                app: app(Apps::class),
                branch: $this->user()->getCurrentBranch(),
                user: $this->user(),
                options: $options,
            )
        )->execute();
    }

    private function user(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }
}
