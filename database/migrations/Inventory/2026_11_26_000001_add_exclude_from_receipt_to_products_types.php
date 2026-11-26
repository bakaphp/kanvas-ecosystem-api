<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'inventory';

    public function up(): void
    {
        $schema = Schema::connection('inventory');

        $schema->table('products_types', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('products_types', 'exclude_from_receipt')) {
                $table->boolean('exclude_from_receipt')->default(false)->after('is_published');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('inventory')->table('products_types', function (Blueprint $table) {
            $table->dropColumn('exclude_from_receipt');
        });
    }
};
