<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Kanvas\CustomFields\Models\CustomFieldsTypes;

class CustomFieldsTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        CustomFieldsTypes::create([
            'name' => 'Text',
            'description' => 'Text Fields',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
