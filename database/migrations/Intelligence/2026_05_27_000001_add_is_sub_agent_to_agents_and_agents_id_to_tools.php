<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        if (! Schema::connection('intelligence')->hasColumn('agents', 'is_sub_agent')) {
            Schema::table('agents', function (Blueprint $table) {
                $table->boolean('is_sub_agent')->default(false)->after('is_active');
            });
        }

        if (! Schema::connection('intelligence')->hasColumn('nervous_system_tools', 'agents_id')) {
            Schema::table('nervous_system_tools', function (Blueprint $table) {
                $table->unsignedBigInteger('agents_id')->nullable();
                $table->foreign('agents_id')->references('id')->on('agents')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('nervous_system_tools', function (Blueprint $table) {
            $table->dropForeign(['agents_id']);
            $table->dropColumn('agents_id');
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('is_sub_agent');
        });
    }
};
