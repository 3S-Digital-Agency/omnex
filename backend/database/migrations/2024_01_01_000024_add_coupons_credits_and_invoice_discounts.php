<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('discount_type', 16)->default('percent'); // percent | amount
            $table->unsignedInteger('discount_value')->default(0);
            $table->string('currency', 3)->default('usd');
            $table->string('stripe_coupon_id')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('times_redeemed')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('coupon_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->unsignedInteger('discount_amount')->default(0);
            $table->string('currency', 3)->default('usd');
            $table->timestamps();

            $table->unique(['coupon_id', 'organization_id', 'subscription_id']);
        });

        // Signed ledger: a positive entry adds credit, a negative entry
        // consumes it. The balance is the sum of all entries.
        Schema::create('org_credit_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->integer('amount');
            $table->string('currency', 3)->default('usd');
            $table->string('reason', 64);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedInteger('discount')->default(0)->after('amount');
            $table->unsignedInteger('credit_applied')->default(0)->after('discount');
            $table->unsignedInteger('amount_due')->default(0)->after('credit_applied');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['discount', 'credit_applied', 'amount_due']);
        });

        Schema::dropIfExists('org_credit_entries');
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
