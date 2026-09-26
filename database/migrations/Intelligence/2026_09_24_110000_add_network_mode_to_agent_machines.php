<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the API reaches a container this machine runs.
 *
 * SSH is the launcher, not the transport: polling talks HTTP to the container's private address, and
 * only a machine the API genuinely cannot route to needs `docker exec curl` over SSH. Which of those
 * applies is a property of the machine, so it lives here rather than in code.
 *
 * `shared_network` publishes nothing at all — the container is addressed by name on a Docker network
 * the API is also on — which is why there is no port allocator on that path.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->table('agent_machines', function (Blueprint $table): void {
            $table->string('network_mode', 32)->default('ssh_exec')->after('region');
            $table->string('docker_network', 191)->nullable()->after('network_mode');
            $table->string('private_host', 191)->nullable()->after('docker_network');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agent_machines', function (Blueprint $table): void {
            $table->dropColumn(['network_mode', 'docker_network', 'private_host']);
        });
    }
};
