<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'event';

    public function up(): void
    {
        Schema::connection('event')->table('participant_pass_scans', function (Blueprint $table) {
            $table->string('source', 10)->default('qr')->after('result');
        });
    }

    public function down(): void
    {
        Schema::connection('event')->table('participant_pass_scans', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
