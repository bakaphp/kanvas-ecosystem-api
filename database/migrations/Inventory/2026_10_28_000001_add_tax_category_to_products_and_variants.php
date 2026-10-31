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

        $schema->table('products', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('products', 'tax_category')) {
                $table->string('tax_category', 32)->nullable();
            }

            if (! $schema->hasColumn('products', 'tax_code_override')) {
                $table->string('tax_code_override', 32)->nullable();
            }
        });

        $schema->table('products_variants', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('products_variants', 'is_taxable')) {
                $table->boolean('is_taxable')->default(true);
            }

            if (! $schema->hasColumn('products_variants', 'tax_category')) {
                $table->string('tax_category', 32)->nullable();
            }

            if (! $schema->hasColumn('products_variants', 'tax_code_override')) {
                $table->string('tax_code_override', 32)->nullable();
            }

            if (! $schema->hasColumn('products_variants', 'tax_code_id')) {
                $table->bigInteger('tax_code_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('inventory')->table('products', function (Blueprint $table) {
            $table->dropColumn(['tax_category', 'tax_code_override']);
        });

        Schema::connection('inventory')->table('products_variants', function (Blueprint $table) {
            $table->dropColumn(['is_taxable', 'tax_category', 'tax_code_override', 'tax_code_id']);
        });
    }
};
