<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('attributes_values', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->after('value')
                ->nullable()
                ->index()
                ->constrained('attributes_values')
                ->cascadeOnDelete();

            $table->tinyInteger('has_children')->nullable()->index()->after('parent_id');
            $table->string('path')->nullable()->index()->after('has_children');
        });
    }

    public function down(): void
    {
    }
};
