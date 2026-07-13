<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured addresses for Organizations, mirroring the shape of `peoples_address` and sharing its
 * `address_types` lookup.
 *
 * Multi-row and typed rather than columns on `organizations`: a single field can't express "bill here, ship
 * there", and Scribe invoices already carry separate billing and shipping snapshots.
 */
return new class () extends Migration {
    protected $connection = 'crm';

    public function up(): void
    {
        Schema::connection('crm')->create('organizations_address', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organizations_id')->index();
            $table->unsignedBigInteger('address_type_id')->nullable()->index();

            $table->string('address')->nullable();
            $table->string('address_2')->nullable();
            $table->string('city')->nullable();
            $table->string('county')->nullable();
            $table->string('state')->nullable();
            $table->char('zip', 50)->nullable();

            $table->unsignedInteger('countries_id')->nullable()->index();
            $table->unsignedInteger('city_id')->nullable()->index();
            $table->unsignedInteger('state_id')->nullable()->index();

            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();

            $table->index(['organizations_id', 'address_type_id'], 'org_addr_type_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->dropIfExists('organizations_address');
    }
};
