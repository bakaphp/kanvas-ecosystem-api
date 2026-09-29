<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `event_version_facilitators` had two model classes.
 *
 * The original (`Event\Events\Models\EventVersionFacilitator`, Sept 2024) was only ever named in
 * `CreateSystemModule`, so it collected every `system_modules` row while nothing queried through
 * it. The replacement (`Event\Facilitators\Models\EventVersionFacilitator`, added April 2026 in
 * #9275) is what the GraphQL builder, the facilitator mutation and the Intras importer use — and
 * it is the one that carries `NoAppRelationshipTrait` / `NoCompanyRelationshipTrait`, which this
 * table needs since it has no `apps_id` / `companies_id`.
 *
 * Left alone, the first feature to hang a custom field or a module-scoped permission off the live
 * model would not find its system module. This repoints the existing rows rather than orphaning
 * them and letting setup create a second set: verified at write time that nothing references them
 * by id across the twelve tables carrying a `system_modules_id`, and that neither class had any
 * `apps_custom_fields` rows.
 */
return new class () extends Migration {
    private const string DEAD_CLASS = 'Kanvas\\Event\\Events\\Models\\EventVersionFacilitator';
    private const string LIVE_CLASS = 'Kanvas\\Event\\Facilitators\\Models\\EventVersionFacilitator';

    public function up(): void
    {
        $this->repoint(self::DEAD_CLASS, self::LIVE_CLASS);
    }

    public function down(): void
    {
        $this->repoint(self::LIVE_CLASS, self::DEAD_CLASS);
    }

    /**
     * Rows already sitting on the target are skipped, so this is safe to re-run and safe on an
     * install where setup happened to register the live class first.
     */
    private function repoint(string $from, string $to): void
    {
        $existing = DB::connection('ecosystem')
            ->table('system_modules')
            ->where('model_name', $to)
            ->pluck('apps_id')
            ->all();

        DB::connection('ecosystem')
            ->table('system_modules')
            ->where('model_name', $from)
            ->when($existing !== [], fn ($query) => $query->whereNotIn('apps_id', $existing))
            ->update(['model_name' => $to]);

        // Anything left is a duplicate of a row that already points at the target.
        DB::connection('ecosystem')
            ->table('system_modules')
            ->where('model_name', $from)
            ->delete();
    }
};
