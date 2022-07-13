<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCompaniesBranchesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('companies_branches', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('companies_id')->nullable()->index('companies_id');
            $table->integer('users_id')->nullable()->index('users_id');
            $table->string('name', 64)->nullable();
            $table->string('address')->nullable();
            $table->string('email', 50)->nullable()->index('email');
            $table->string('phone', 65)->nullable();
            $table->string('zipcode', 50)->nullable()->index('zipcode');
            $table->boolean('is_default')->nullable()->default(false)->index('is_default');
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->boolean('is_deleted')->nullable()->default(false);

            $table->index(['created_at', 'updated_at', 'is_deleted'], 'created_at_updated_at_is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('companies_branches');
    }
}
