<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function getConnection(): ?string
    {
        return 'intelligence';
    }

    public function up(): void
    {
        Schema::connection('intelligence')->table('agent_conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('apps_id')->default(0)->after('user_id');
            $table->unsignedBigInteger('companies_id')->default(0)->after('apps_id');

            $table->index(['apps_id', 'companies_id', 'user_id', 'updated_at'], 'conversations_tenant_index');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agent_conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_tenant_index');
            $table->dropColumn(['apps_id', 'companies_id']);
        });
    }
};
