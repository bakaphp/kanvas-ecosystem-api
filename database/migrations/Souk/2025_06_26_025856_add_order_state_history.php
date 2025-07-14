<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('order_transitions_history', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('apps_id')->index();
            $table->bigInteger('companies_id')->nullable()->index();
            $table->bigInteger('order_id')->index();
            $table->foreignId('transition_id')->constrained('order_status_transitions');
            $table->foreignId('from_status_id')->constrained('order_statuses');
            $table->foreignId('to_status_id')->constrained('order_statuses');

            $table->text('description')->nullable();
            $table->text('metadata')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('changed_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->bigInteger('changed_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('order_transitions_history');
    }
};
