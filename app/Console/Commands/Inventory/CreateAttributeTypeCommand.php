<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Support\Str;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Inventory\Attributes\Enums\AttributeTypeEnum;
use Kanvas\Inventory\Attributes\Models\AttributesTypes;
use Laravel\Prompts\Exceptions\NonInteractiveValidationException;

use function Laravel\Prompts\info;

use RuntimeException;
use Symfony\Component\Console\Exception\InvalidArgumentException;

class CreateAttributeTypeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:create-attribute-types {--app_id=} {--name}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Create a new set of attribute types global or to an App';

    /**
     * @psalm-suppress MixedArgument
     *
     * @throws InvalidArgumentException
     * @throws ModelNotFoundException
     * @throws RuntimeException
     * @throws NonInteractiveValidationException
     */
    public function handle(): void
    {
        $data = $this->options();

        if (! empty($data['app_id']) || !empty($data['name'])) {
            // Validation
            $validator = Validator::make($data, [
                'app_id' => 'required_with:name',
                'name' => 'required_with:app_id',
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->error($error);
                }
                return;
            }

            $app = Apps::getById($this->option('app_id'));
            $this->overwriteAppService($app);
            $this->createPrivateAttributeTYpe($app, $data['name']);
            return;
        }

        $this->createGlobalAttributesTypes();
    }

    public function createGlobalAttributesTypes(): void
    {
        AttributesTypes::firstOrCreate([
            'name' => AttributeTypeEnum::INPUT->value,
            'apps_id' => 0,
            'companies_id' => 0,
        ], [
            'slug' => Str::slug(AttributeTypeEnum::INPUT->value)
        ]);

        AttributesTypes::firstOrCreate([
            'name' => AttributeTypeEnum::CHECKBOX->value,
            'apps_id' => 0,
            'companies_id' => 0,
        ], [
            'slug' => Str::slug(AttributeTypeEnum::CHECKBOX->value)
        ]);

        AttributesTypes::firstOrCreate([
            'name' => AttributeTypeEnum::JSON->value,
            'apps_id' => 0,
            'companies_id' => 0,
        ], [
            'slug' => Str::slug(AttributeTypeEnum::JSON->value)
        ]);

        info('Attribute Types created for all apps');
    }

    public function createPrivateAttributeTYpe(Apps $app, String $name): void
    {
        AttributesTypes::firstOrCreate([
            'name' => $name,
            'apps_id' => (int) $app->getId(),
        ], [
            'slug' => Str::slug($name)
        ]);

        info('Attribute Type ' . $name . ' created for app - ' . $app->getId());
    }
}
