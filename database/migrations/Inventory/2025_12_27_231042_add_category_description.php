<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up()
    {
        Schema::connection('inventory') // Adjust if you use a different connection name
            ->table('categories', function (Blueprint $table) {
                // Add description
                $table->text('description')
                    ->nullable()
                    ->after('slug');

                // Add is_featured
                $table->boolean('is_featured')
                    ->default(false)
                    ->after('is_published')
                    ->index();

                $table->decimal('weight', 10, 2)
                    ->default(0)
                    ->change();
            });
    }

    public function down()
    {
        Schema::connection('inventory')
            ->table('categories', function (Blueprint $table) {
                $table->dropColumn('description');
                $table->dropColumn('is_featured');

                // Revert decimal → int
                $table->integer('position')
                    ->default(0)
                    ->change();
            });
    }
};
