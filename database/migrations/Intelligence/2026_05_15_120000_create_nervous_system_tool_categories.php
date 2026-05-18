<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::create('nervous_system_tool_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            // apps_id=0 → platform-seeded category visible to all apps.
            // apps_id=N → app-specific category created by that app's admin.
            $table->unsignedBigInteger('apps_id')->default(0);
            $table->string('slug', 64);
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->string('icon', 64)->nullable();
            $table->unsignedInteger('display_order')->default(100);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->unique(['apps_id', 'slug'], 'uniq_tool_category_app_slug');
            $table->index(['apps_id', 'is_active', 'is_deleted'], 'idx_tool_category_app_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nervous_system_tool_categories');
    }
};
