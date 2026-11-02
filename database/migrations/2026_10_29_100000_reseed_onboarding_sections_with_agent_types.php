<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Kanvas\Companies\Onboarding\Services\OnboardingSectionsSeedService;

/**
 * Catalog agents now name their agent type by handler class, so a company can be provisioned
 * from the synced types alone. Refreshes the platform cards at app 0; app overrides are untouched.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! is_file(OnboardingSectionsSeedService::defaultPath())) {
            return;
        }

        new OnboardingSectionsSeedService()->seed(0);
    }

    public function down(): void
    {
    }
};
