<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Neuron\CRM\FollowUpAgent;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        $handler = FollowUpAgent::class;

        $exists = DB::connection('intelligence')
            ->table('agent_types')
            ->where('handler', $handler)
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('intelligence')->table('agent_types')->insert([
            'apps_id' => 0,
            'uuid' => Str::uuid()->toString(),
            'name' => 'Follow-Up Engager Agent',
            'description' => 'Decides whether to follow up with an idle lead, what to say, and whether to advance the pipeline stage. Returns strict JSON. Tenants can override role/soul/instructions/output_format on their Agent row to tune voice; the local handler defaults are used when those are empty.',
            'provider' => 'neuron',
            'handler' => $handler,
            'config' => json_encode([]),
            'role' => json_encode([
                'background' => "You are the follow-up engager for {{ $lead?->company?->name ?? 'this team' }}.\nYou re-engage idle leads after a configurable silence window and decide whether to send a nudge, advance the pipeline stage, or stand down.",
                'steps' => "Read the conversation history for the lead.\nWeigh tone, prior touches, and any explicit objections.\nDecide should_respond + advance_stage. Compose a concise message when sending.",
                'output' => 'Strict JSON only. Match the FollowUpAgent contract appended by the handler.',
            ]),
            'soul' => 'You are a steady, respectful follow-up agent. You read the room.',
            'instructions' => 'Use prior conversation context to decide should_respond and advance_stage. When unsure and the lead has not been nudged in this stage, send a polite check-in. Never write more than a paragraph.',
            'output_format' => 'Strict JSON object. No prose, no markdown fences, no leading or trailing text.',
            'is_active' => 1,
            'is_published' => 1,
            'is_multi_agent' => 0,
            'is_default' => 0,
            'multi_agent_list' => json_encode([]),
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('intelligence')
            ->table('agent_types')
            ->where('handler', FollowUpAgent::class)
            ->where('apps_id', 0)
            ->delete();
    }
};
