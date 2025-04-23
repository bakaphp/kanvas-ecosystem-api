<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Attributes\Models\AttributesTypes;

class MigrateAttributeTypesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:migrate-attribute-type';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test attribute update query with rollback';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        DB::beginTransaction();

        try {
            $baseAttributes = AttributesTypes::where('apps_id', 0)
                ->where('companies_id', 0)
                ->select('id', 'slug')
                ->get();

            $this->info('Global Attributes Types found: '.$baseAttributes->count());

            if ($baseAttributes->isEmpty()) {
                $this->info('No global attributes types found.');
                DB::rollBack();

                return;
            }

            $baseSlugs = $baseAttributes->pluck('slug');
            $matchingAttributes = AttributesTypes::whereIn('slug', $baseSlugs)
                ->where('apps_id', '!=', 0)
                ->get(['id', 'slug']);

            $this->info('Matching attributes found: '.$matchingAttributes->count());

            if ($matchingAttributes->isEmpty()) {
                $this->info('No matching attributes found.');
                DB::rollBack();

                return;
            }

            $slugToIdMap = $baseAttributes->pluck('id', 'slug');

            $affectedAttributes = Attributes::whereIn('attributes_type_id', $matchingAttributes->pluck('id'))
                                        ->get();

            if ($affectedAttributes->isEmpty()) {
                $this->info('Not attribute to update found.');
                DB::rollBack();

                return;
            }

            $updatedCount = 0;
            $updatedIds = [];
            foreach ($affectedAttributes as $attribute) {
                $newAttributeTypeId = $slugToIdMap[$attribute->attributeType->slug] ?? null;
                if ($newAttributeTypeId) {
                    $attribute->attributes_type_id = $newAttributeTypeId;
                    $attribute->save();
                    $updatedCount++;
                    $updatedIds[] = $attribute->id;
                }
            }

            $this->info('Updated attributes IDs:');
            foreach ($updatedIds as $id) {
                $this->line(" - ID: {$id}");
            }
        } catch (\Exception $e) {
            DB::rollBack();

            $this->error('An error occurred: '.$e->getMessage());
        }
    }
}
