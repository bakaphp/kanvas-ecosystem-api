<?php

declare(strict_types=1);

namespace App\Console\Commands\AccessControl;

use Bouncer;
use Illuminate\Console\Command;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Models\Apps;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class GrantOwnerAllPermissionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:grant-owner-all-permissions {--app_id=} {--all}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Grant all permissions to existing Owner roles using Bouncer everything()';

    public function handle(): int
    {
        $appId = $this->option('app_id');
        $updateAll = $this->option('all');

        if (! $appId && ! $updateAll) {
            warning('Please provide --app_id=<id> or --all flag');

            return self::FAILURE;
        }

        $updatedCount = 0;

        if ($updateAll) {
            $apps = Apps::all();
        } else {
            $apps = Apps::where('id', $appId)->get();
        }

        foreach ($apps as $app) {
            $scope = RolesEnums::getScope($app);
            Bouncer::scope()->to($scope);

            $ownerRole = Role::where('name', 'Owner')
                ->where('scope', $scope)
                ->first();

            if (! $ownerRole) {
                $this->line("  No Owner role found for app: {$app->name} (ID: {$app->id})");

                continue;
            }

            Bouncer::allow('Owner')->everything();
            info("✓ Granted all permissions to Owner role for app: {$app->name} (ID: {$app->id})");
            $updatedCount++;
        }

        $this->newLine();
        info("Summary: Updated {$updatedCount} Owner role(s)");

        return self::SUCCESS;
    }
}
