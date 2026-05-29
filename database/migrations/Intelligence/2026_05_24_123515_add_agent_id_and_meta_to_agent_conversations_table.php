<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function getConnection(): ?string
    {
        return 'intelligence';
    }

    /**
     * Adds the link from a conversation to the Kanvas Agent that owns it
     * (existing schema only carries user_id; Hermes-runtime conversations are
     * agent-owned, not user-owned) plus a generic meta blob for runtime-specific
     * session metadata (Hermes' model, system_prompt, source, parent_session_id,
     * cost, watermark, handoff state). Both nullable so the in-Kanvas
     * KanvasConversationStore flow keeps working untouched.
     */
    public function up(): void
    {
        Schema::connection('intelligence')->table('agent_conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_id')->nullable()->after('user_id');
            $table->longText('meta')->nullable();

            $table->index(['agent_id', 'updated_at'], 'agent_conversations_agent_recent_idx');
            $table->index(['agent_id', 'apps_id', 'companies_id'], 'agent_conversations_agent_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agent_conversations', function (Blueprint $table) {
            $table->dropIndex('agent_conversations_agent_recent_idx');
            $table->dropIndex('agent_conversations_agent_tenant_idx');
            $table->dropColumn(['agent_id', 'meta']);
        });
    }
};
