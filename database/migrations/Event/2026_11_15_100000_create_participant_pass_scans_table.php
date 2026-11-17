<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'event';

    public function up(): void
    {
        Schema::connection('event')->create('participant_pass_scans', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('participant_passes_id')->nullable()->index();
            $table->unsignedBigInteger('event_version_id')->nullable()->index();
            $table->unsignedBigInteger('products_id')->index();
            $table->unsignedBigInteger('users_id')->index();
            $table->string('result', 30)->index();
            $table->unsignedBigInteger('admitted_event_version_id')->nullable()->unique();
            $table->timestamp('scanned_at')->index();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::connection('event')->dropIfExists('participant_pass_scans');
    }
};
