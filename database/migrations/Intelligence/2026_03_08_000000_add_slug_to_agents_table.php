<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name')->index();
        });

        // Backfill slugs for existing agents
        $agents = DB::table('agents')->whereNull('slug')->get();
        foreach ($agents as $agent) {
            DB::table('agents')
                ->where('id', $agent->id)
                ->update(['slug' => Str::slug($agent->name)]);
        }
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
