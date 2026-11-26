<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'event';

    public function up(): void
    {
        if (! Schema::connection('event')->hasColumn('participant_pass_scans', 'use_number')) {
            Schema::connection('event')->table('participant_pass_scans', function (Blueprint $table) {
                $table->unsignedSmallInteger('use_number')->nullable()->after('admitted_event_version_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('event')->hasColumn('participant_pass_scans', 'use_number')) {
            Schema::connection('event')->table('participant_pass_scans', function (Blueprint $table) {
                $table->dropColumn('use_number');
            });
        }
    }
};
