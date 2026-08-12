<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('event_types', function (Blueprint $table): void {
            $table->tinyInteger('is_default')->default(0)->index()->after('name');
        });

        $scopes = DB::connection('event')
            ->table('event_types')
            ->where('is_deleted', 0)
            ->select(['apps_id', 'companies_id'])
            ->distinct()
            ->get();

        foreach ($scopes as $scope) {
            $defaultCategory = DB::connection('event')
                ->table('event_categories')
                ->where('apps_id', $scope->apps_id)
                ->where('companies_id', $scope->companies_id)
                ->where('is_default', 1)
                ->where('is_deleted', 0)
                ->orderBy('id')
                ->first();

            $eventTypeId = $defaultCategory?->event_type_id
                ?? DB::connection('event')
                    ->table('event_types')
                    ->where('apps_id', $scope->apps_id)
                    ->where('companies_id', $scope->companies_id)
                    ->where('is_deleted', 0)
                    ->orderBy('id')
                    ->value('id');

            if ($eventTypeId !== null) {
                DB::connection('event')
                    ->table('event_types')
                    ->where('id', $eventTypeId)
                    ->update(['is_default' => 1]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('event_types', function (Blueprint $table): void {
            $table->dropIndex(['is_default']);
            $table->dropColumn('is_default');
        });
    }
};
