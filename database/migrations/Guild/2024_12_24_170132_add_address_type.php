<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('address_types', function (Blueprint $table) {
            $table->id(); // Primary key
            $table->unsignedBigInteger('apps_id')->index(); // Foreign key to apps
            $table->unsignedBigInteger('companies_id')->index(); // Foreign key to companies
            $table->string('name'); // Name of the address type
            $table->timestamps(); // created_at and updated_at
            $table->boolean('is_deleted')->default(false); // Soft delete indicator
        });

        Schema::table('peoples_address', function (Blueprint $table) {
            $table->unsignedBigInteger('address_type_id')->nullable()->after('peoples_id')->index();
            $table->foreign('address_type_id')->references('id')->on('address_types')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('address_types');

        Schema::table('peoples_address', function (Blueprint $table) {
            $table->dropForeign(['address_type_id']);
            $table->dropColumn('address_type_id');
        });
    }
};
