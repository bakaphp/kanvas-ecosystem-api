<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

return new class () extends Migration {
    public function up(): void
    {
        $templates = [
            'scribe-sow' => '846ca5cb7b9fd962505b74fe978c0dcc4e8f07ecc8a3e0fe95305c7799ba3b2a',
            'scribe-ecf-invoice' => '423d0ed771d5091fe51aae5499fa5ff4ba7d353814c098543d1f0531892d95e3',
        ];

        foreach ($templates as $name => $hash) {
            DB::table('email_templates')
                ->where('name', $name)
                ->where('engine', 'twig')
                ->where('is_deleted', 0)
                ->whereRaw('SHA2(template, 256) = ?', [$hash])
                ->update([
                    'template' => File::get(resource_path('views/pdf/' . $name . '.twig')),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Keep the presentation update; reverting must not overwrite later template edits.
    }
};
