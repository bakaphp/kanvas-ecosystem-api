<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `integrations.config` is consumed by ConfigValidation as a company-facing FORM schema — every key in
 * it becomes a validation rule. The platform-side descriptor of a service (an MCP server's url,
 * transport, auth mode) is not a form field and would turn into bogus rules, so it needs its own home.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        Schema::connection('workflow')->table('integrations', function (Blueprint $table): void {
            $table->json('metadata')->nullable()->after('config');
        });
    }

    public function down(): void
    {
        Schema::connection('workflow')->table('integrations', function (Blueprint $table): void {
            $table->dropColumn('metadata');
        });
    }
};
