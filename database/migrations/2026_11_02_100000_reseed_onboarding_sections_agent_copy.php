<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Kanvas\Companies\Onboarding\Services\OnboardingSectionsSeedService;

/**
 * The catalog agents carry user-facing names and descriptions (what you can ask it, not how it is
 * built), and provisioning copies that description onto the agent. Refreshes the app-0 cards.
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
