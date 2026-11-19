<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'meeting';

    public function up(): void
    {
        // People live on the crm connection, so this is an id the actions validate, not a foreign key.
        Schema::connection('meeting')->table('meetings', function (Blueprint $table) {
            $table->unsignedBigInteger('people_id')->nullable()->after('users_id');
            $table->index(['apps_id', 'companies_id', 'people_id'], 'meetings_people_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('meeting')->table('meetings', function (Blueprint $table) {
            $table->dropIndex('meetings_people_idx');
            $table->dropColumn('people_id');
        });
    }
};
