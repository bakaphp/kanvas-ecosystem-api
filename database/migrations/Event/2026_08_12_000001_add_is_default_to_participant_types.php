<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('event')->table('participant_types', function (Blueprint $table) {
            $table->tinyInteger('is_default')->default(0)->index()->after('name');
        });
    }

    public function down(): void
    {
        Schema::connection('event')->table('participant_types', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
