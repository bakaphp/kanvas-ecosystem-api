<?php

declare(strict_types=1);

namespace App\Console\Commands\Social;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Social\Channels\Models\ChannelCategories;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Laravel\Prompts\Exceptions\NonInteractiveValidationException;
use RuntimeException;
use Symfony\Component\Console\Exception\InvalidArgumentException;

class CreateChannelCategoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:create-social-channel-category {--app_id=} {--company_id=}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Create a new set of categories for channels';

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
        $appId = 0;
        $companyId = 0;

        if ($this->option('app_id')) {
            $app = Apps::getById($this->option('app_id'));
            $this->overwriteAppService($app);
            $appId = $app->getId();
        }

        if ($this->option('company_id') && $app) {
            $companyId = (Companies::getById($this->option('company_id'), $app))->getId();
        }

        ChannelCategories::firstOrCreate([
            'name' => ChannelCategoryEnum::EMAIL->value,
            'apps_id' => $appId,
            'companies_id' => $companyId,
        ]);

        ChannelCategories::firstOrCreate([
            'name' => ChannelCategoryEnum::SMS->value,
            'apps_id' => $appId,
            'companies_id' => $companyId,
        ]);

        ChannelCategories::firstOrCreate([
            'name' => ChannelCategoryEnum::WHATSAPP->value,
            'apps_id' => $appId,
            'companies_id' => $companyId,
        ]);

        ChannelCategories::firstOrCreate([
            'name' => ChannelCategoryEnum::INTERNAL_NOTES->value,
            'apps_id' => $appId,
            'companies_id' => $companyId,
        ]);

        ChannelCategories::firstOrCreate([
            'name' => ChannelCategoryEnum::SYSTEM_NOTES->value,
            'apps_id' => $appId,
            'companies_id' => $companyId,
        ]);

        echo('Channels Categories created successfully for app - ' . $appId);
    }
}
