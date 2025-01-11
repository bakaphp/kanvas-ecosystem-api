<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\VAuto;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes;
use Kanvas\Inventory\Attributes\Models\Attributes as ModelsAttributes;
use Kanvas\Inventory\Attributes\Models\AttributesTypes;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\VariantsAttributes;
use Kanvas\Inventory\Variants\Models\VariantsChannels;

class GenerateInventoryFiltrableAttributesCommand extends Command
{
    use KanvasJobsTrait;

    private const ATTRIBUTE_TYPE_INPUT = 'input';

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'kanvas:vauto-inventory-attribute-generation {app_id : The ID of the application} {company_id : The ID of the company}';

    /**
     * The console command description.
     */
    protected $description = 'Generate filterable inventory attributes (mileage and price ranges) for a specific app';

    /**
     * @var array<string, array{name: string, slug: string, values: array<int, int>}>
     */
    private array $attributeConfigs = [
        'millage_range' => [
            'name' => 'Mileage Range',
            'slug' => 'millage_range',
            'values' => [0, 100000],
        ],
        'price_range' => [
            'name' => 'Price Range',
            'slug' => 'price_range',
            'values' => null, // Will be dynamically set based on max price
        ],
    ];

    /**
     * Execute the console command.
     *
     * @throws Exception When required models or data cannot be found
     */
    public function handle(): int
    {
        try {
            $app = Apps::getById((int) $this->argument('app_id'));
            $this->overwriteAppService($app);
            $company = Companies::getById((int) $this->argument('company_id'));

            $attributeType = AttributesTypes::fromApp($app)
                ->fromCompany($company)
                ->where('name', self::ATTRIBUTE_TYPE_INPUT)
                ->firstOrFail();

            // Initialize price range configuration
            $defaultChannel = Channels::getDefault($company, $app);
            $maxPrice = VariantsChannels::fromCompany($company)
                ->where('channels_id', $defaultChannel->getId())
                ->max('price') ?? 0;

            $this->attributeConfigs['price_range']['values'] = [0, (int) $maxPrice];

            foreach ($this->attributeConfigs as $config) {
                //$this->processAttribute($config, $company, $app, $attributeType);
            }

            $this->generateBodyAttributeValues($app, $company);

            $this->info('Successfully generated inventory attributes.');

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error("Failed to generate attributes: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    private function processAttribute(
        array $config,
        Companies $company,
        Apps $app,
        AttributesTypes $attributeType
    ): void {
        $attributeDTO = new Attributes(
            $company,
            $app,
            $company->user,
            $config['name'],
            $config['slug'],
            $attributeType
        );

        $attribute = (new CreateAttribute($attributeDTO, $company->user))->execute();
        $attribute->addValues($config['values']);

        $this->info("Created attribute: {$config['name']}");
    }

    private function generateBodyAttributeValues(Apps $app, Companies $company): void
    {
        $attribute = ModelsAttributes::fromApp($app)->fromCompany($company)->where('slug', 'body')->firstOrFail();

        $attributeValues = VariantsAttributes::query()
            ->where('attributes_id', $attribute->getId())
            ->select('value')
            ->distinct()
            ->get();

        foreach ($attributeValues as $value) {
            $attribute->addValue($value->value);
        }
    }
}
