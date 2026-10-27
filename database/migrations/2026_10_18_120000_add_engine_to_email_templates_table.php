<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->string('engine', 10)->default('twig')->after('template');
        });

        // Every row that exists today was written for Blade; new rows are Twig.
        DB::table('email_templates')->update(['engine' => 'blade']);
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn('engine');
        });
    }
};
