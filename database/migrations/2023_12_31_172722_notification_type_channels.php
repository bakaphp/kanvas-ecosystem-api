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
        Schema::create(
            'notification_type_channels',
            function (Blueprint $table) {
                $table->id();
                $table->integer('notification_type_id')->index();
                $table->integer('notification_channel_id')->index();
                $table->integer('template_id')->index();
                $table->dateTime('created_at')->nullable()->index('created_at');
                $table->dateTime('updated_at')->nullable()->index('updated_at');
                $table->integer('is_deleted')->nullable()->default(0)->index('is_deleted');
                $table->index(['notification_type_id', 'notification_channel_id', 'template_id'], 'notification_type_channels');
                $table->index(['notification_type_id', 'notification_channel_id'], 'notification_type_channels_1');

                //foreign
                $table->foreign('notification_type_id', 'notification_type_channels_ibfk_1')->references('id')->on('notification_types');
                $table->foreign('notification_channel_id', 'notification_type_channels_ibfk_2')->references('id')->on('notification_channels');
                $table->foreign('template_id', 'notification_type_channels_ibfk_3')->references('id')->on('email_templates');
            }
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_type_channels');
    }
};
