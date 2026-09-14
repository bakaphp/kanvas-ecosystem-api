<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The `wordpress` integration row was created without the `config` field schema its own connector
 * docs specify. `ConfigValidation` loops that schema to build its rules, so an empty one validates
 * nothing — setup only survives because `WordPressHandler::setup()` re-checks the three credentials
 * itself and would have thrown anyway.
 *
 * The schema is also the only machine-readable description of what this integration needs, which is
 * what a form — or an agent walking an admin through setup — has to read to know what to ask for.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $config = [
            'site_url' => ['type' => 'text', 'required' => true],
            'username' => ['type' => 'text', 'required' => true],
            'application_password' => ['type' => 'text', 'required' => true],
            'default_post_status' => ['type' => 'text', 'required' => false],
            'default_author_id' => ['type' => 'text', 'required' => false],
            'default_categories' => ['type' => 'text', 'required' => false],
            'default_tags' => ['type' => 'text', 'required' => false],
            'allow_term_creation' => ['type' => 'text', 'required' => false],
        ];

        DB::connection('workflow')
            ->table('integrations')
            ->where('name', 'wordpress')
            ->where('apps_id', 0)
            // Only fill a row that has none; never overwrite a schema someone has since curated.
            ->whereIn('config', ['[]', '{}', ''])
            ->update([
                'config' => json_encode($config),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::connection('workflow')
            ->table('integrations')
            ->where('name', 'wordpress')
            ->where('apps_id', 0)
            ->update([
                'config' => json_encode([]),
                'updated_at' => now(),
            ]);
    }
};
