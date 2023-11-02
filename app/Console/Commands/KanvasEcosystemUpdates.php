<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Bouncer;
use Illuminate\Console\Command;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Enums\AppEnums;

class KanvasEcosystemUpdates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:app-ecosystem-update';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Run the Kanvas Ecosystem Updates';

    /**
     * Execute the console command.
     *
     */
    public function handle()
    {
        $this->info(sprintf('Kanvas Ecosystem Version: %s', AppEnums::VERSION->getValue()));
        match (AppEnums::VERSION->getValue()) {
            '1.0-BETA-14' => $this->versionBeta14(),
            default => $this->versionBeta14(),
        };
    }

    public function versionBeta14()
    {
        $keys = AppKey::notDeleted()->get();
        foreach ($keys as $appKey) {
            $app = $appKey->app;
            Bouncer::scope()->to(RolesEnums::getScope($app));

            $appKey->user->assign(RolesEnums::OWNER->value);
            $appKey->user->assign(RolesEnums::ADMIN->value);
        }

        $this->info('Updated to 1.0-BETA-14 successfully');
    }
}
