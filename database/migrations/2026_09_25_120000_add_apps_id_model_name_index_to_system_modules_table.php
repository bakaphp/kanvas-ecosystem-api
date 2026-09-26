<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * system_modules is looked up by (apps_id, model_name) on nearly every permission, file and
 * notification-type resolution, but only had the two columns indexed separately. MySQL then picked
 * one and filtered the rest: the batched IN() lookup used `apps_id` alone and read 302 rows to
 * return 46.
 *
 * apps_id leads because it is also the scope on its own, so this index serves `where apps_id = ?`
 * too. It is not unique: the table currently holds 21 duplicate (apps_id, model_name) groups, so a
 * unique index would fail until those are reconciled.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('ecosystem')->table('system_modules', function (Blueprint $table) {
            $table->index(['apps_id', 'model_name'], 'system_modules_apps_id_model_name_index');
        });
    }

    public function down(): void
    {
        Schema::connection('ecosystem')->table('system_modules', function (Blueprint $table) {
            $table->dropIndex('system_modules_apps_id_model_name_index');
        });
    }
};
