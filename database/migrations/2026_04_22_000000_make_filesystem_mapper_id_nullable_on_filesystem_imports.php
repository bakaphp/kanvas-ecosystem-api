<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('filesystem_imports', function (Blueprint $table) {
            $table->dropForeign('filesystem_imports_filesystem_mapper_id_foreign');
            $table->unsignedBigInteger('filesystem_mapper_id')->nullable()->change();
            $table->foreign('filesystem_mapper_id')
                ->references('id')->on('filesystem_mappers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('filesystem_imports', function (Blueprint $table) {
            $table->dropForeign('filesystem_imports_filesystem_mapper_id_foreign');
            $table->unsignedBigInteger('filesystem_mapper_id')->nullable(false)->change();
            $table->foreign('filesystem_mapper_id')
                ->references('id')->on('filesystem_mappers');
        });
    }
};
