<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('agent_machines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('companies_id')->index();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('host');
            $table->integer('ssh_port')->default(22);
            $table->string('ssh_user');
            $table->text('ssh_private_key');
            $table->string('region')->nullable();
            $table->integer('port_range_start')->default(20000);
            $table->integer('port_range_end')->default(30000);
            $table->integer('max_agents')->default(100);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_machines');
    }
};
