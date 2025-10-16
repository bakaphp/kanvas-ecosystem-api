<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('event_statuses', function (Blueprint $table) {
            // Array of status names/IDs that this status can transition to
            $table->json('valid_transitions')->nullable()->after('name');

            // Validation rules that must be met before transitioning TO this status
            // Example: {"metadata_fields": ["reason"], "custom_validations": ["has_participants"]}
            $table->json('transition_validations')->nullable()->after('valid_transitions');
        });
    }

    public function down(): void
    {
        Schema::table('event_statuses', function (Blueprint $table) {
            $table->dropColumn(['valid_transitions', 'transition_validations']);
        });
    }
};
