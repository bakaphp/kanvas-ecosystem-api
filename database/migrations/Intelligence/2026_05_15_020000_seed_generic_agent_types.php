<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Laravel\KanvasGenericLaravelAgent;
use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;

return new class () extends Migration {
    protected $connection = 'intelligence';

    /**
     * @var array<int, array{name: string, description: string, provider: string, handler: string, soul: string}>
     */
    private array $types = [
        [
            'name' => 'Generic Laravel Agent',
            'description' => 'Generic agent using the Laravel-AI runtime — picks the LLM provider from the agent\'s model config. Use for fast tests of the Laravel-AI path.',
            'provider' => 'laravel-ai',
            'handler' => KanvasGenericLaravelAgent::class,
            'soul' => 'You are a helpful, concise assistant running inside Kanvas via the Laravel-AI runtime.',
        ],
        [
            'name' => 'Generic Neuron Agent',
            'description' => 'Generic agent using the NeuronAI runtime — same persona, alternate runtime. Use to A/B the same prompt across both engines.',
            'provider' => 'neuron',
            'handler' => KanvasGenericNeuronAgent::class,
            'soul' => 'You are a helpful, concise assistant running inside Kanvas via the NeuronAI runtime.',
        ],
    ];

    public function up(): void
    {
        foreach ($this->types as $type) {
            $exists = DB::connection('intelligence')
                ->table('agent_types')
                ->where('handler', $type['handler'])
                ->where('apps_id', 0)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::connection('intelligence')->table('agent_types')->insert([
                'apps_id' => 0,
                'uuid' => Str::uuid()->toString(),
                'name' => $type['name'],
                'description' => $type['description'],
                'provider' => $type['provider'],
                'handler' => $type['handler'],
                'config' => json_encode([]),
                'role' => json_encode([]),
                'soul' => $type['soul'],
                'instructions' => 'Answer the user\'s question directly. Ask clarifying questions only when truly ambiguous.',
                'output_format' => 'Plain text. Use short paragraphs; use lists only when enumerating distinct items.',
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
    }

    public function down(): void
    {
        $handlers = array_column($this->types, 'handler');

        DB::connection('intelligence')
            ->table('agent_types')
            ->whereIn('handler', $handlers)
            ->where('apps_id', 0)
            ->delete();
    }
};
