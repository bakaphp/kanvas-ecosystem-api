<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured addresses for Organizations, mirroring `peoples_address`.
 *
 * Organizations were the only party in Kanvas with just a free-text `address` string, while People,
 * Companies and Users all carry multiple typed addresses. That gap blocks anything that needs a REAL address:
 * Scribe invoices already have separate `billing_address_snapshot` and `shipping_address_snapshot` columns,
 * and Mercury's AR API rejects a half-filled address outright — it wants address1 / city / region /
 * postalCode / country, all or nothing.
 *
 * A single field can't express "bill here, ship there", which is why this is multi-row and typed rather than
 * a set of columns bolted onto `organizations`. The legacy `organizations.address` string is left alone —
 * plenty of code still reads it — but structured consumers should use this table.
 */
return new class () extends Migration {
    protected $connection = 'crm';

    public function up(): void
    {
        Schema::connection('crm')->create('organizations_address', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organizations_id')->index();
            $table->unsignedBigInteger('address_type_id')->nullable()->index();
            $table->unsignedInteger('users_id')->nullable();

            // Mailing name — who the envelope is addressed to. Not always the organization's own name.
            $table->string('name')->nullable();
            $table->string('address')->nullable();
            $table->string('address_2')->nullable();
            $table->string('city')->nullable();
            $table->string('county')->nullable();
            $table->string('state')->nullable();
            $table->char('zip', 50)->nullable();

            $table->unsignedInteger('countries_id')->nullable()->index();
            $table->unsignedInteger('city_id')->nullable()->index();
            $table->unsignedInteger('state_id')->nullable()->index();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

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
