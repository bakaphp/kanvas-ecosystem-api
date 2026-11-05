<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        DB::connection('commerce')->statement(
            "ALTER TABLE orders MODIFY status ENUM(
                'draft',
                'completed',
                'canceled',
                'cancelled',
                'pending',
                'failed',
                'on_hold'
            ) NULL"
        );
    }

    public function down(): void
    {
        DB::connection('commerce')->update(
            "UPDATE orders SET status = 'pending' WHERE status = 'on_hold'"
        );

        DB::connection('commerce')->statement(
            "ALTER TABLE orders MODIFY status ENUM(
                'draft',
                'completed',
                'canceled',
                'cancelled',
                'pending',
                'failed'
            ) NULL"
        );
    }
};
