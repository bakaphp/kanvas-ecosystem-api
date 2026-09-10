<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Whether a company's owner or an admin may decide this approval without having been resolved onto
     * it. Off by default, and that default is the point: for a bill the approver list IS the control,
     * so adopting approvals must never quietly make every admin an approver of everything.
     *
     * A policy turns it on when its resolvers cannot be relied on to find the right humans — held
     * agent message drafts resolve channel members, and tens of thousands of channels have none.
     */
    public function up(): void
    {
        Schema::table('approval_policies', function (Blueprint $table): void {
            $table->boolean('allow_authority_override')->default(false)->after('notify');
        });
    }

    public function down(): void
    {
        Schema::table('approval_policies', function (Blueprint $table): void {
            $table->dropColumn('allow_authority_override');
        });
    }
};
