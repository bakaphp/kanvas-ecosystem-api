<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('onboarding_sections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('apps_id')->default(0)->comment('0 = platform-global, read by every app; an app row with the same slug overrides it');
            $table->string('slug', 64);
            $table->string('label');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('icon', 64)->nullable();
            $table->boolean('is_available')->default(true)->comment('false renders "Soon"; still selectable as a waitlist pick');
            $table->integer('display_order')->default(0);
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);

            $table->unique(['apps_id', 'slug']);
            $table->index(['apps_id', 'is_active', 'display_order']);
        });

        $this->seedGlobalSections();
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_sections');
    }

    /**
     * The six platform cards every new app reads, from the same file the seed command uses, so a
     * fresh database (CI included) can run the onboarding flow without a seed step. Options name
     * integrations by `integration_name`; the id is resolved against the global integrations rows
     * when that table exists, and left null otherwise.
     */
    private function seedGlobalSections(): void
    {
        $path = database_path('seeders/data/onboarding/global.json');
        if (! is_file($path)) {
            return;
        }

        $sections = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['sections'] ?? [];
        $now = now();

        foreach ($sections as $section) {
            $exists = DB::table('onboarding_sections')
                ->where('apps_id', 0)
                ->where('slug', (string) $section['slug'])
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('onboarding_sections')->insert([
                'uuid' => (string) Str::uuid7(),
                'apps_id' => 0,
                'slug' => (string) $section['slug'],
                'label' => (string) $section['label'],
                'title' => (string) $section['title'],
                'description' => $section['description'] ?? null,
                'icon' => $section['icon'] ?? null,
                'is_available' => (bool) ($section['is_available'] ?? true),
                'display_order' => (int) ($section['display_order'] ?? 0),
                'config' => json_encode($this->resolveIntegrationIds((array) ($section['config'] ?? [])), JSON_THROW_ON_ERROR),
                'is_active' => true,
                'is_deleted' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function resolveIntegrationIds(array $config): array
    {
        $hasIntegrations = Schema::connection('workflow')->hasTable('integrations');

        if (! isset($config['requirements']) || ! is_array($config['requirements'])) {
            return $config;
        }

        foreach ($config['requirements'] as &$requirement) {
            if (! isset($requirement['options']) || ! is_array($requirement['options'])) {
                continue;
            }

            foreach ($requirement['options'] as &$option) {
                if (empty($option['integration_name']) || ! empty($option['integration_id'])) {
                    continue;
                }

                $option['integration_id'] = $hasIntegrations
                    ? DB::connection('workflow')->table('integrations')
                        ->where('name', (string) $option['integration_name'])
                        ->where('apps_id', 0)
                        ->where('is_deleted', 0)
                        ->value('id')
                    : null;
            }
            unset($option);
        }
        unset($requirement);

        return $config;
    }
};
