<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflows\Integrations;

use Baka\Support\Str;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\Status;
use Laravel\Prompts\Exceptions\NonInteractiveValidationException;

use function Laravel\Prompts\info;

use RuntimeException;
use Symfony\Component\Console\Exception\InvalidArgumentException;

class CreateStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:create-workflow-status {--app_id=}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Create a new set of status to an App';

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

        if ($this->option('app_id')) {
            $app = Apps::getById($this->option('app_id'));
            $this->overwriteAppService($app);
            $appId = $app->getId();
        }

        Status::firstOrCreate([
            'name' => StatusEnum::ACTIVE->value,
            'apps_id' => $appId,
        ], [
            'slug' => Str::slug(StatusEnum::ACTIVE->value)
        ]);

        Status::firstOrCreate([
            'name' => StatusEnum::CONNECTED->value,
            'apps_id' => $appId,
        ], [
            'slug' => Str::slug(StatusEnum::CONNECTED->value)
        ]);

        Status::firstOrCreate([
            'name' => StatusEnum::FAILED->value,
            'apps_id' => $appId,
        ], [
            'slug' => Str::slug(StatusEnum::FAILED->value)
        ]);

        Status::firstOrCreate([
            'name' => StatusEnum::OFFLINE->value,
            'apps_id' => $appId,
        ], [
            'slug' => Str::slug(StatusEnum::OFFLINE->value)
        ]);

        info('Integration status created successfully for app - ' . $appId);
    }
}
