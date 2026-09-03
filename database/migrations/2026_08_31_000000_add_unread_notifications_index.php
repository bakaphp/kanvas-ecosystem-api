<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The unread-badge query filters `read = 0 AND notification_type_id != 18` on top of the
 * allNotifications/fromCompany scopes. `read` sits in no composite and `!=` is not a usable
 * range, so the existing users_id_companies_id_apps_id_notification_type_id_is_deleted index
 * stops at its 3-column prefix and the count scans every notification the user ever received.
 *
 * Equalities first, the inequality last. users_id_companies_id_apps_id_is_deleted becomes an
 * exact prefix of this index, so it is dropped rather than kept as dead write cost.
 */
return new class () extends Migration {
    private const string INDEX_NAME = 'notifications_user_unread_type_idx';
    private const string REDUNDANT_INDEX = 'users_id_companies_id_apps_id_is_deleted';

    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(
                [
                    'users_id',
                    'companies_id',
                    'apps_id',
                    'is_deleted',
                    'read',
                    'notification_type_id',
                ],
                self::INDEX_NAME
            );
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(self::REDUNDANT_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(
                ['users_id', 'companies_id', 'apps_id', 'is_deleted'],
                self::REDUNDANT_INDEX
            );
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(self::INDEX_NAME);
        });
    }
};
