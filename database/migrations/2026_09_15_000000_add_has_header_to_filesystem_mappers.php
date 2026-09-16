<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A mapper is no longer necessarily fed by a CSV — a connector can apply one to a single API
 * record, in which case there is no header row to declare. Existing rows all came from the file
 * import path, so they default to 1.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('filesystem_mappers', function (Blueprint $table) {
            $table->boolean('has_header')->default(1)->after('file_header');
        });
    }

    public function down(): void
    {
        Schema::table('filesystem_mappers', function (Blueprint $table) {
            $table->dropColumn('has_header');
        });
    }
};
