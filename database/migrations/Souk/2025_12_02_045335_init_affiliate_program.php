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
        // ============================================================================
        // AFFILIATE PROGRAMS - Main configuration table
        // ============================================================================
        Schema::create('affiliate_programs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->bigInteger('apps_id');
            $table->bigInteger('companies_id');
            $table->bigInteger('users_id');

            // Program Info
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('slug')->unique();

            // Program Settings
            $table->boolean('is_active')->default(true);
            $table->boolean('accepts_new_affiliates')->default(true);

            // Commission Structure
            $table->enum('default_commission_type', ['percentage', 'fixed', 'hybrid', 'tiered'])->default('percentage');
            $table->decimal('default_commission_rate', 5, 2)->default(10.00);
            $table->boolean('tier_based_commission')->default(false);

            // Tracking Configuration
            $table->integer('default_cookie_duration_days')->default(30);
            $table->integer('default_attribution_window_days')->default(30);
            $table->boolean('require_approval')->default(true);

            // Payout Configuration
            $table->decimal('min_payout_amount', 10, 2)->default(100.00);
            $table->enum('payout_frequency', ['weekly', 'monthly', 'quarterly'])->default('monthly');
            $table->json('payout_methods_allowed')->nullable(); // ['wallet', 'paypal', 'bank_transfer']

            // Restrictions
            $table->json('restricted_countries')->nullable();
            $table->json('restricted_categories')->nullable();
            $table->json('restricted_products')->nullable();

            // Metadata
            $table->json('configuration')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);

            // Indexes
            $table->index('apps_id');
            $table->index('companies_id');
            $table->index('is_active');
            $table->index('users_id');
            $table->index('accepts_new_affiliates');
        });

        // ============================================================================
        // AFFILIATE TIERS - Commission tiering structure
        // ============================================================================
        Schema::create('affiliate_tiers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->bigInteger('apps_id');
            $table->bigInteger('companies_id');
            $table->bigInteger('users_id');
            $table->bigInteger('affiliate_programs_id')->unsigned();

            // Tier Info
            $table->string('name'); // 'Bronze', 'Silver', 'Gold'
            $table->text('description')->nullable();
            $table->integer('level')->default(1);

            // Tier Requirements
            $table->integer('min_referrals')->default(0);
            $table->decimal('min_monthly_sales', 12, 2)->nullable();
            $table->decimal('min_conversion_rate', 5, 2)->nullable();
            $table->integer('min_days_active')->default(0);

            // Tier Benefits - Commission
            $table->decimal('base_commission_rate', 5, 2);
            $table->decimal('commission_multiplier', 3, 2)->default(1.00);
            $table->decimal('bonus_commission_rate', 5, 2)->nullable();

            // Tier Benefits - Features
            $table->boolean('early_payout_eligibility')->default(false);
            $table->boolean('marketing_material_access')->default(true);
            $table->boolean('dedicated_manager')->default(false);
            $table->boolean('priority_support')->default(false);
            $table->boolean('advanced_reporting')->default(false);

            // Additional Perks (JSON)
            $table->json('benefits')->nullable(); // Custom perks as JSON

            $table->decimal('weight', 5, 2)->default(0.00); // For ranking tiers
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);

            // Indexes
            $table->index('apps_id');
            $table->index('companies_id');
            $table->index('is_active');
            $table->index('users_id');
            $table->index('affiliate_programs_id');
            $table->index(['affiliate_programs_id', 'level']);
            $table->foreign('affiliate_programs_id')
                ->references('id')
                ->on('affiliate_programs')
                ->onDelete('cascade');
        });

        // ============================================================================
        // AFFILIATES - Core affiliate management
        // ============================================================================
        Schema::create('affiliates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->bigInteger('apps_id');
            $table->bigInteger('companies_id');
            $table->bigInteger('users_id')->unsigned();

            // Affiliate Info
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('website_url')->nullable();
            $table->json('social_profiles')->nullable(); // {twitter, instagram, youtube, tiktok, etc}

            // Affiliate Details
            $table->text('bio')->nullable();
            $table->string('profile_image_url')->nullable();
            $table->enum('affiliate_type', ['individual', 'business', 'influencer', 'agency'])->default('individual');

            // Program Assignment
            $table->bigInteger('affiliate_programs_id')->unsigned();
            $table->bigInteger('affiliate_tiers_id')->unsigned()->nullable();

            // Status & Approval
            $table->enum('status', ['pending', 'approved', 'active', 'suspended', 'inactive', 'rejected'])->default('pending');
            $table->timestamp('approval_date')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->bigInteger('approved_by')->unsigned()->nullable(); // User who approved

            // Financial Configuration
            $table->enum('commission_type', ['percentage', 'fixed', 'hybrid', 'tiered'])->default('percentage');
            $table->decimal('commission_rate', 5, 2)->default(10.00);
            $table->decimal('min_payout_threshold', 10, 2)->default(100.00);
            $table->enum('payout_method', ['wallet', 'bank_transfer', 'paypal', 'stripe'])->default('wallet');
            $table->enum('payout_frequency', ['weekly', 'monthly', 'quarterly'])->default('monthly');

            // Tracking Configuration
            $table->string('unique_identifier')->unique(); // Unique slug for tracking
            $table->enum('tracking_method', ['utm_params', 'subdomain', 'direct_link', 'cookies', 'short_codes'])->default('utm_params');
            $table->integer('cookie_duration_days')->default(30);
            $table->integer('attribution_window_days')->default(30);

            // Performance Metrics (Cached/Denormalized)
            $table->bigInteger('total_clicks')->default(0);
            $table->bigInteger('total_conversions')->default(0);
            $table->decimal('total_commission_earned', 12, 2)->default(0.00);
            $table->decimal('total_commission_paid', 12, 2)->default(0.00);
            // pending_commission is calculated as: total_commission_earned - total_commission_paid

            // Payout Banking Details
            $table->json('banking_details')->nullable(); // {bank_name, account_holder, account_number, routing_number, swift_code, iban}
            $table->json('paypal_details')->nullable(); // {email, merchant_id}
            $table->json('stripe_details')->nullable(); // {connected_account_id}

            // Metadata & Custom Fields
            $table->json('configuration')->nullable();
            $table->timestamp('last_payout_date')->nullable();
            $table->timestamp('last_activity_at')->nullable();

            $table->timestamps();
            $table->boolean('is_deleted')->default(false);

            // Indexes
            $table->index('apps_id');
            $table->index('companies_id');
            $table->index('users_id');
            $table->index('status');
            $table->index('unique_identifier');
            $table->index('affiliate_programs_id');
            $table->index('affiliate_tiers_id');
            $table->index('created_at');
            $table->index(['apps_id', 'status']);

            // Foreign Keys
            $table->foreign('affiliate_programs_id')
                ->references('id')
                ->on('affiliate_programs')
                ->onDelete('cascade');
            $table->foreign('affiliate_tiers_id')
                ->references('id')
                ->on('affiliate_tiers')
                ->onDelete('set null');
        });

        // ============================================================================
        // AFFILIATE LINKS - Trackable affiliate links with flexible targeting
        // ============================================================================
        Schema::create('affiliate_links', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->bigInteger('apps_id');
            $table->bigInteger('companies_id');
            $table->bigInteger('affiliates_id')->unsigned();

            // Basic Info
            $table->string('name')->nullable(); // "Summer Sale Banner", "Product Review Link"
            $table->text('description')->nullable();

            // URL/Link Generation
            $table->string('custom_slug')->nullable(); // yoursite.com/aff/john-summer-sale
            $table->string('short_code')->unique(); // yoursite.com/aff_abc123xyz
            $table->string('destination_url')->nullable(); // Can override where link goes

            // Link Type - Controls how commission is calculated
            $table->enum('link_type', [
                'general',           // Links to homepage, no specific product targeting
                'product',           // Links to single product
                'category',          // Links to category page
                'collection',        // Links to custom collection/bundle
                'content',           // Links to blog/content page
                'custom_url',        // Custom external URL with tracking
                'checkout_preset',    // Pre-filled cart/checkout
            ])->default('general');

            // Primary Target (for analytics/reporting - not limiting)
            $table->bigInteger('primary_target_id')->unsigned()->nullable();
            $table->string('primary_target_type')->nullable(); // 'product', 'category', 'content', etc

            // Link Configuration (JSON for flexibility)
            $table->json('config')->nullable();
            // Examples:
            // {
            //   "destination": "https://example.com/products/item-123",
            //   "utm_source": "affiliate",
            //   "utm_medium": "partner_link",
            //   "utm_campaign": "summer_sale",
            //   "utm_content": "john_doe_banner",
            //   "eligible_products": [123, 456, 789],
            //   "eligible_categories": [5, 8],
            //   "minimum_cart_value": 50.00,
            //   "excluded_products": [999],
            //   "bonus_products": [123],
            //   "pre_fill_cart": {
            //     "product_ids": [123, 456],
            //     "quantities": [1, 2]
            //   },
            //   "coupon_code": "AFFILIATE_JOHN",
            //   "custom_landing_page": true,
            //   "suppress_other_promotions": false
            // }

            // Tracking & Attribution
            $table->enum('tracking_method', ['utm_params', 'custom_slug', 'subdomain', 'short_code', 'api', 'cookie'])->default('utm_params');
            $table->integer('cookie_duration_days')->default(30);
            $table->integer('attribution_window_days')->default(30);
            $table->enum('attribution_model', ['first_click', 'last_click', 'linear', 'time_decay'])->default('last_click');

            // Commission Override (per-link specific)
            $table->boolean('override_commission')->default(false);
            $table->enum('commission_type', ['percentage', 'fixed', 'tiered', 'hybrid'])->nullable();
            $table->decimal('commission_rate', 5, 2)->nullable(); // Overrides affiliate's default
            $table->string('commission_note')->nullable(); // "50% off promo - higher commission"

            // Performance Metrics (Cached/Denormalized)
            $table->bigInteger('impression_count')->default(0);
            $table->bigInteger('click_count')->default(0);
            $table->bigInteger('conversion_count')->default(0);
            $table->decimal('total_order_value', 12, 2)->default(0.00);
            $table->decimal('total_commission', 12, 2)->default(0.00);

            // Status & Metadata
            $table->boolean('is_active')->default(true);
            $table->boolean('is_approved')->default(true);
            $table->text('approval_notes')->nullable();
            $table->json('configuration')->nullable();

            $table->timestamps();
            $table->boolean('is_deleted')->default(false);

            // Indexes
            $table->index('apps_id');
            $table->index('companies_id');
            $table->index('affiliates_id');
            $table->index('link_type');
            $table->index('is_active');
            $table->index('click_count');
            $table->index('created_at');
            $table->index(['affiliates_id', 'is_active']);
            $table->unique(['apps_id', 'custom_slug']);
            $table->unique(['apps_id', 'short_code']);

            // Foreign Keys
            $table->foreign('affiliates_id')
                ->references('id')
                ->on('affiliates')
                ->onDelete('cascade');
        });

        // ============================================================================
        // AFFILIATE CLICKS - Click tracking with session/cookie data
        // ============================================================================
        Schema::create('affiliate_clicks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->bigInteger('apps_id');
            $table->bigInteger('affiliates_id')->unsigned();
            $table->bigInteger('affiliate_links_id')->unsigned();

            // Session & Visitor Info
            $table->string('session_id')->nullable();
            $table->string('ip_address', 45)->nullable(); // IPv4 or IPv6
            $table->text('user_agent')->nullable();
            $table->string('referrer_url', 500)->nullable();
            $table->string('landing_page_url', 500)->nullable();

            // Device Info
            $table->enum('device_type', ['desktop', 'mobile', 'tablet'])->nullable();
            $table->string('browser')->nullable();
            $table->string('os')->nullable(); // Operating system
            $table->string('country_code', 2)->nullable();

            // Tracking Cookie
            $table->string('tracking_cookie')->unique();
            $table->timestamp('cookie_expires_at')->nullable();

            // Conversion Status
            $table->boolean('converted')->default(false);
            $table->timestamp('conversion_date')->nullable();

            $table->timestamp('clicked_at')->useCurrent();

            // Indexes
            $table->index('apps_id');
            $table->index('affiliates_id');
            $table->index('affiliate_links_id');
            $table->index('tracking_cookie');
            $table->index('cookie_expires_at');
            $table->index(['affiliates_id', 'clicked_at']);
            $table->index('converted');

            // Foreign Keys
            $table->foreign('affiliates_id')
                ->references('id')
                ->on('affiliates')
                ->onDelete('cascade');
            $table->foreign('affiliate_links_id')
                ->references('id')
                ->on('affiliate_links')
                ->onDelete('cascade');
        });

        // ============================================================================
        // AFFILIATE CONVERSIONS - Sale attribution and commission
        // ============================================================================
        Schema::create('affiliate_conversions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->bigInteger('apps_id');
            $table->bigInteger('affiliates_id')->unsigned();
            $table->bigInteger('affiliate_clicks_id')->unsigned()->nullable();
            $table->bigInteger('affiliate_links_id')->unsigned()->nullable();
            $table->bigInteger('orders_id')->unsigned();

            // Attribution Model & Confirmation
            $table->enum('attribution_model', ['first_click', 'last_click', 'linear', 'time_decay'])->default('last_click');
            $table->boolean('confirmed')->default(false);
            $table->timestamp('confirmed_at')->nullable();

            // Order & Commission Details (locked at conversion time)
            $table->decimal('order_total', 10, 2);
            $table->decimal('eligible_amount', 10, 2); // After exclusions
            $table->enum('commission_type', ['percentage', 'fixed', 'hybrid', 'tiered']);
            $table->decimal('commission_rate', 5, 2);
            $table->decimal('commission_amount', 12, 2);

            // Status Management
            $table->enum('status', ['pending', 'confirmed', 'paid', 'reversed', 'disputed'])->default('pending');
            $table->text('dispute_reason')->nullable();
            $table->timestamp('dispute_resolved_at')->nullable();
            $table->text('notes')->nullable();

            // Payout Reference
            $table->bigInteger('commission_payout_id')->unsigned()->nullable();

            // Timestamps
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('apps_id');
            $table->index('affiliates_id');
            $table->index('orders_id');
            $table->index('status');
            $table->index('confirmed');
            $table->index('converted_at');
            $table->index(['affiliates_id', 'status']);
            $table->index(['affiliates_id', 'converted_at']);

            // Foreign Keys
            $table->foreign('affiliates_id')
                ->references('id')
                ->on('affiliates')
                ->onDelete('cascade');
            $table->foreign('affiliate_clicks_id')
                ->references('id')
                ->on('affiliate_clicks')
                ->onDelete('set null');
            $table->foreign('affiliate_links_id')
                ->references('id')
                ->on('affiliate_links')
                ->onDelete('set null');
            $table->foreign('orders_id')
                ->references('id')
                ->on('orders')
                ->onDelete('cascade');
        });

        // ============================================================================
        // AFFILIATE COMMISSION PAYOUTS - Payout batch management
        // ============================================================================
        Schema::create('affiliate_commission_payouts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->bigInteger('apps_id');
            $table->bigInteger('companies_id');
            $table->bigInteger('affiliates_id')->unsigned();

            // Payout Period
            $table->date('period_start');
            $table->date('period_end');

            // Commission Summary
            $table->integer('total_conversions')->default(0);
            $table->decimal('total_order_value', 12, 2)->default(0.00);
            $table->decimal('gross_commission', 12, 2)->default(0.00);
            $table->decimal('fees', 12, 2)->default(0.00);
            $table->decimal('adjustments', 12, 2)->default(0.00); // Manual adjustments
            $table->decimal('net_commission', 12, 2)->default(0.00);

            // Payout Details
            $table->enum('payout_status', ['pending_approval', 'approved', 'processing', 'completed', 'failed', 'cancelled'])->default('pending_approval');
            $table->enum('payout_method', ['wallet', 'bank_transfer', 'paypal', 'stripe'])->nullable();
            $table->string('payout_reference')->nullable();
            $table->date('payout_date')->nullable();

            // Approval Tracking
            $table->timestamp('approved_at')->nullable();
            $table->bigInteger('approved_by')->unsigned()->nullable(); // User who approved
            $table->text('approval_notes')->nullable();

            // Transaction Linking
            $table->bigInteger('transaction_id')->unsigned()->nullable(); // wallets/transactions link
            $table->string('payment_intent_id')->nullable(); // Stripe/PayPal reference

            // Notes for failures/disputes
            $table->text('failure_reason')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();

            // Metadata
            $table->json('configuration')->nullable();

            $table->timestamps();
            $table->boolean('is_deleted')->default(false);

            // Indexes
            $table->index('apps_id');
            $table->index('companies_id');
            $table->index('affiliates_id');
            $table->index('payout_status');
            $table->index('payout_date');
            $table->index(['affiliates_id', 'period_start', 'period_end'],  'affiliates_period_idx');

            // Foreign Keys
            $table->foreign('affiliates_id')
                ->references('id')
                ->on('affiliates')
                ->onDelete('cascade');
        });

        // ============================================================================
        // AFFILIATE PERFORMANCE METRICS - Daily aggregated analytics
        // ============================================================================
        Schema::create('affiliate_performance_metrics', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('apps_id');
            $table->bigInteger('affiliates_id')->unsigned();
            $table->date('metric_date');

            // Daily Aggregated Data
            $table->bigInteger('clicks')->default(0);
            $table->bigInteger('impressions')->default(0);
            $table->bigInteger('conversions')->default(0);
            $table->bigInteger('unique_visitors')->default(0);

            // Revenue
            $table->decimal('order_value', 12, 2)->default(0.00);
            $table->decimal('commission_earned', 12, 2)->default(0.00);

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index('apps_id');
            $table->index('affiliates_id');
            $table->index('metric_date');
            $table->index(['affiliates_id', 'metric_date']);
            $table->unique(['affiliates_id', 'metric_date']);

            // Foreign Keys
            $table->foreign('affiliates_id')
                ->references('id')
                ->on('affiliates')
                ->onDelete('cascade');
        });

        // ============================================================================
        // AFFILIATE LINK TEMPLATES - Template system for bulk link generation
        // ============================================================================
        Schema::create('affiliate_link_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->bigInteger('apps_id');
            $table->bigInteger('affiliates_id')->unsigned();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();

            // Template Configuration
            $table->string('link_type');
            $table->json('config_template')->nullable();

            // Usage
            $table->integer('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('apps_id');
            $table->index('affiliates_id');

            // Foreign Keys
            $table->foreign('affiliates_id')
                ->references('id')
                ->on('affiliates')
                ->onDelete('cascade');
        });

        // ============================================================================
        // ENHANCEMENTS TO EXISTING TABLES
        // ============================================================================

        /*  // Add columns to orders table for affiliate tracking
         if (! Schema::hasColumn('orders', 'affiliates_id')) {
             Schema::table('orders', function (Blueprint $table) {
                 $table->bigInteger('affiliates_id')->unsigned()->nullable()->after('users_id');
                 $table->bigInteger('affiliate_conversions_id')->unsigned()->nullable()->after('affiliates_id');
                 $table->decimal('affiliate_commission_amount', 12, 2)->nullable()->after('discount_amount');
                 $table->string('affiliate_tracking_cookie')->nullable()->after('checkout_token');

                 // Indexes
                 $table->index('affiliates_id');
                 $table->foreign('affiliates_id')
                     ->references('id')
                     ->on('affiliates')
                     ->onDelete('set null');
             });
         } */
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop foreign key constraints from orders table
        /*         Schema::table('orders', function (Blueprint $table) {
                    if (Schema::hasColumn('orders', 'affiliates_id')) {
                        $table->dropForeign(['affiliates_id']);
                        $table->dropIndex(['affiliates_id']);
                        $table->dropColumn(['affiliates_id', 'affiliate_conversions_id', 'affiliate_commission_amount', 'affiliate_tracking_cookie']);
                    }
                });
         */
        // Drop all affiliate-related tables in reverse order
        Schema::dropIfExists('affiliate_link_templates');
        Schema::dropIfExists('affiliate_performance_metrics');
        Schema::dropIfExists('affiliate_commission_payouts');
        Schema::dropIfExists('affiliate_conversions');
        Schema::dropIfExists('affiliate_clicks');
        Schema::dropIfExists('affiliate_links');
        Schema::dropIfExists('affiliates');
        Schema::dropIfExists('affiliate_tiers');
        Schema::dropIfExists('affiliate_programs');
    }
};
