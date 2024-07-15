<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('apps_id')->index();
            $table->bigInteger('companies_id')->index();
            $table->uuid('uuid')->index();
            $table->string('tracking_client_id', 255)->nullable()->index();
            $table->string('user_email', 255)->nullable()->index();
            $table->string('user_phone', 255)->nullable()->index();
            $table->string('token', 255)->nullable()->index();
            $table->bigInteger('order_number')->nullable()->index();
            $table->unsignedBigInteger('billing_address_id')->nullable()->index();
            $table->unsignedBigInteger('shipping_address_id')->nullable()->index();
            $table->unsignedBigInteger('users_id')->nullable()->index();
            $table->decimal('total_gross_amount', 10, 2)->nullable();
            $table->decimal('total_net_amount', 10, 2)->nullable();
            $table->decimal('shipping_price_gross_amount', 10, 2)->nullable();
            $table->decimal('shipping_price_net_amount', 10, 2)->nullable();
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->string('discount_name', 255)->nullable();
            $table->unsignedBigInteger('voucher_id')->nullable()->index();
            $table->string('language_code', 10)->nullable()->index();
            $table->enum('status', ['draft', 'completed', 'canceled', 'cancelled'])->nullable()->index();
            $table->string('shipping_method_name', 255)->nullable();
            $table->unsignedBigInteger('shipping_method_id')->nullable()->index();
            $table->boolean('display_gross_prices')->default(false);
            $table->string('translated_discount_name', 255)->nullable();
            $table->text('customer_note')->nullable();
            $table->decimal('weight', 10, 2)->nullable();
            $table->string('checkout_token', 255)->nullable()->index();
            $table->string('currency', 10)->nullable()->index();
            $table->longText('metadata')->nullable();
            $table->longText('private_metadata')->nullable();
            $table->timestamps(); // Includes both `created_at` and `updated_at` columns
            $table->boolean('is_deleted')->default(false)->index();

            $table->unique(['apps_id', 'uuid']);
            $table->unique(['apps_id', 'companies_id', 'order_number']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('apps_id')->index();
            $table->uuid('uuid')->index();
            $table->string('product_name', 255);
            $table->string('product_sku', 255);
            $table->integer('quantity');
            $table->decimal('unit_price_net_amount', 10, 2)->nullable();
            $table->decimal('unit_price_gross_amount', 10, 2)->nullable();
            $table->boolean('is_shipping_required')->default(false);
            $table->unsignedBigInteger('order_id')->index();
            $table->integer('quantity_fulfilled')->default(0);
            $table->unsignedBigInteger('variant_id')->index();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->string('translated_product_name', 255)->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('translated_variant_name', 255)->nullable();
            $table->string('variant_name', 255);
            $table->timestamps(); // Adds `created_at` and `updated_at` columns
            $table->boolean('is_deleted')->default(false);

            $table->unique(['apps_id', 'uuid']);
            // Foreign key constraints
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_items');
    }
};
