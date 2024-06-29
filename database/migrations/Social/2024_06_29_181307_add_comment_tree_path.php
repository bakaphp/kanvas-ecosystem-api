<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('message_comments', function (Blueprint $table) {
            $table->string('path')->after('parent_id')->nullable()->index();
            $table->integer('parent_id')->nullable()->default(null)->change();
            //add total_liked, total_save. total_shared
            $table->integer('total_liked')->default(0)->after('reactions_count')->index();
            $table->integer('total_saved')->default(0)->after('total_liked')->index();
            $table->integer('total_shared')->default(0)->after('total_saved')->index();

            $table->bigIncrements('id')->change();
            $table->bigInteger('parent_id')->change();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->bigIncrements('id')->change();
            $table->bigInteger('parent_id')->change();
            $table->bigInteger('message_id')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('message_comments', function (Blueprint $table) {
            $table->dropColumn('path');
            $table->integer('parent_id')->default(0)->nullable(false)->change();
            $table->dropColumn('total_liked');
            $table->dropColumn('total_saved');
            $table->dropColumn('total_shared');
        });


    }
};
