<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('affiliate_clicks', function (Blueprint $table) {
            if (! Schema::hasColumn('affiliate_clicks', 'is_deleted')) {
                $table->tinyInteger('is_deleted')->default(0)->after('clicked_at');
                $table->index('is_deleted');
            }
        });

        Schema::table('affiliate_conversions', function (Blueprint $table) {
            if (! Schema::hasColumn('affiliate_conversions', 'is_deleted')) {
                $table->tinyInteger('is_deleted')->default(0)->after('updated_at');
                $table->index('is_deleted');
            }
        });

        Schema::table('affiliate_link_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('affiliate_link_templates', 'is_deleted')) {
                $table->tinyInteger('is_deleted')->default(0)->after('updated_at');
                $table->index('is_deleted');
            }
        });

        Schema::table('affiliate_performance_metrics', function (Blueprint $table) {
            if (! Schema::hasColumn('affiliate_performance_metrics', 'is_deleted')) {
                $table->tinyInteger('is_deleted')->default(0)->after('updated_at');
                $table->index('is_deleted');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affiliate_clicks', function (Blueprint $table) {
            if (Schema::hasColumn('affiliate_clicks', 'is_deleted')) {
                $table->dropIndex(['is_deleted']);
                $table->dropColumn('is_deleted');
            }
        });

        Schema::table('affiliate_conversions', function (Blueprint $table) {
            if (Schema::hasColumn('affiliate_conversions', 'is_deleted')) {
                $table->dropIndex(['is_deleted']);
                $table->dropColumn('is_deleted');
            }
        });

        Schema::table('affiliate_link_templates', function (Blueprint $table) {
            if (Schema::hasColumn('affiliate_link_templates', 'is_deleted')) {
                $table->dropIndex(['is_deleted']);
                $table->dropColumn('is_deleted');
            }
        });

        Schema::table('affiliate_performance_metrics', function (Blueprint $table) {
            if (Schema::hasColumn('affiliate_performance_metrics', 'is_deleted')) {
                $table->dropIndex(['is_deleted']);
                $table->dropColumn('is_deleted');
            }
        });
    }
};
