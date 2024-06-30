<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('path')->after('parent_unique_id')->nullable()->index();
            $table->string('slug', 191)->after('message_types_id')->nullable()->index();

            //make slug unique by apps_id
            $table->unique(['slug', 'apps_id'], 'messages_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('path');
            $table->dropColumn('slug');
        });
    }
};
