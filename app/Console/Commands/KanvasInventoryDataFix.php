<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Baka\Support\Str;
use Illuminate\Console\Command;
use Kanvas\Inventory\Attributes\Models\Attributes;

class KanvasInventoryDefaultUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:inventory-default-fix';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Fix general inventory general data';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $attributes = Attributes::all();

        foreach ($attributes as $attribute) {
            $attribute->slug = Str::slug($attribute->name);
            $attribute->saveQuietly();
        }
    }
}
