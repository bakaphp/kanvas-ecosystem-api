<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the `actions` catalog somewhere to keep what `#[WorkflowAction]` already declares.
 *
 * The attribute has carried a `description` since it was written and the discovery service already
 * returns it — it was dropped here, because the table only had a name. An agent assembling a rule
 * therefore sees a few hundred bare strings and has to infer semantics from the name alone, which is
 * the failure `ListWorkflowOptionsTool` exists to prevent.
 *
 * `requires_config` holds the settings keys a step needs before it can run, so the catalog can answer
 * "not configured, and here is what to set" instead of leaving the agent to guess whether a step is
 * broken or merely unconfigured.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        Schema::connection('workflow')->table('actions', function (Blueprint $table): void {
            $table->string('kind')->default('workflow')->after('model_name');
            $table->text('description')->nullable()->after('kind');
            $table->string('integration')->nullable()->after('description');
            $table->json('requires_config')->nullable()->after('integration');
            $table->json('params')->nullable()->after('requires_config');

            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::connection('workflow')->table('actions', function (Blueprint $table): void {
            $table->dropIndex(['kind']);
            $table->dropColumn([
                'kind',
                'description',
                'integration',
                'requires_config',
                'params',
            ]);
        });
    }
};
