<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'event';

    public function up(): void
    {
        Schema::connection('event')->table('event_versions', function (Blueprint $table) {
            $table->string('reference', 20)->nullable()->after('slug');
            $table->unique(['apps_id', 'reference'], 'event_versions_apps_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('event')->table('event_versions', function (Blueprint $table) {
            $table->dropUnique('event_versions_apps_reference_unique');
            $table->dropColumn('reference');
        });
    }
};
