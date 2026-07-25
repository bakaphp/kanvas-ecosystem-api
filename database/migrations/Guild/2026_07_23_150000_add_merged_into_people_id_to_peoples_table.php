<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('peoples', function (Blueprint $table) {
            $table->unsignedBigInteger('merged_into_people_id')->nullable();
            $table->index('merged_into_people_id', 'merged_into_people_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('peoples', function (Blueprint $table) {
            $table->dropIndex('merged_into_people_idx');
            $table->dropColumn('merged_into_people_id');
        });
    }
};
