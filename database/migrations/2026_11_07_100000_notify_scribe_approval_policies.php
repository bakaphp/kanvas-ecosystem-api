<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Scribe policies were seeded with notify 'none' because the agent tools sent the Slack DM themselves.
     * That DM now comes from the approvals domain (Bill/Invoice deliver it), so a policy left on 'none'
     * would notify nobody at all. Only the seeded value is touched; a tenant that chose another mode keeps it.
     */
    public function up(): void
    {
        DB::table('approval_policies')
            ->whereIn('approval_type', ['approve_bill', 'approve_invoice', 'approve_expense'])
            ->where('notify', 'none')
            ->update(['notify' => 'all']);
    }

    public function down(): void
    {
    }
};
