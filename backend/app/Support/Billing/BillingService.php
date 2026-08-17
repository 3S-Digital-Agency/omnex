<?php

namespace App\Support\Billing;

use App\Contracts\PaymentProviderInterface;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrgCreditEntry;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Audit\AuditLogger;
use App\Support\Notifications\NotificationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * OMNEX owns subscriptions, invoices, coupons and the credit ledger; a
 * PaymentProviderInterface only runs hosted checkout sessions and forwards
 * webhook events. Every mutation is audited, and payment outcomes notify the
 * organization owners.
 *
 * Money model:
 *   amount          = list price of the plan
 *   discount        = coupon discount applied at checkout
 *   credit_applied  = OMNEX credit used against this invoice
 *   amount_due      = amount - discount - credit_applied
 *
 * Coupons are applied at checkout time (Stripe discounts the hosted session);
 * credits are applied when the invoice is recorded (the ledger is OMNEX-side,
 * so a Stripe refund of the credited portion is out of scope in this iteration
 * — the sandbox provider stays fully consistent).
 */
final class BillingService
{
    public function __construct(private PaymentProviderRegistry $providers) {}

    /**
     * @return array<int, array{name: string, label: string, configured: bool}>
     */
    public function providers(): array
    {
        return $this->providers->all();
    }

    /**
     * @return array<int, Plan>
     */
    public function plans(): array
    {
        return Plan::query()->where('active', true)->orderBy('sort')->orderBy('price_monthly')->get()->all();
    }

    private function provider(?string $name = null): PaymentProviderInterface
    {
        $provider = $this->providers->get($name);

        if (! $provider->isConfigured()) {
            throw new PaymentProviderException("The [{$provider->label()}] payment provider is not configured.");
        }

        return $provider;
    }

    /**
     * @return array{subscription: Subscription, checkout_url: string}
     */
    public function subscribe(Organization $organization, string $planSlug, ?string $providerName = null, ?string $couponCode = null): array
    {
        $plan = Plan::query()->where('slug', $planSlug)->where('active', true)->first()
            ?? throw ValidationException::withMessages(['plan' => ['The selected plan is not available.']]);

        $provider = $this->provider($providerName);

        $coupon = null;
        if ($couponCode !== null && trim($couponCode) !== '') {
            $coupon = $this->resolveCoupon($couponCode);
        }

        $existing = Subscription::withoutTenancy()
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['pending', 'active', 'past_due'])
            ->where('plan_id', $plan->id)
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages(['plan' => ['This organization already has an active subscription to this plan.']]);
        }

        $subscription = Subscription::create([
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'provider' => $provider->name(),
            'coupon_id' => $coupon?->id,
            'status' => 'pending',
        ]);

        $checkout = $provider->createCheckout($plan, $subscription, $coupon);

        $subscription->update(['checkout_id' => $checkout['checkout_id']]);

        AuditLogger::record('subscription.created', 'subscription', $subscription->id, null, [
            'plan' => $plan->slug,
            'provider' => $provider->name(),
            'coupon' => $coupon?->code,
        ]);

        return [
            'subscription' => $subscription->fresh(['plan', 'coupon']),
            'checkout_url' => $checkout['url'],
        ];
    }

    public function handleWebhook(PaymentWebhookEvent $event): ?Subscription
    {
        $subscription = $this->resolveSubscription($event);

        if ($subscription === null) {
            return null;
        }

        // Idempotency: a provider may redeliver a webhook. The invoice id is
        // the natural idempotency key — replaying a recorded invoice is a no-op.
        if ($event->providerInvoiceId !== null) {
            $recorded = Invoice::withoutTenancy()
                ->where('organization_id', $subscription->organization_id)
                ->where('provider_invoice_id', $event->providerInvoiceId)
                ->first();

            if ($recorded !== null) {
                return $subscription;
            }
        }

        // Apply within the tenant's context so audit + notifications land on
        // the right organization even though the webhook request is public.
        app(TenantContext::class)->set($subscription->organization_id, $subscription->organization);

        return DB::transaction(function () use ($event, $subscription) {
            $plan = $subscription->plan;

            if ($event->type === 'payment.succeeded') {
                return $this->succeed($subscription, $plan, $event);
            }

            return $this->fail($subscription, $plan, $event);
        });
    }

    public function cancel(Subscription $subscription): Subscription
    {
        $subscription->update([
            'status' => 'canceled',
            'canceled_at' => now(),
        ]);

        AuditLogger::record('subscription.canceled', 'subscription', $subscription->id, null, [
            'plan' => $subscription->plan->slug,
        ]);

        return $subscription->fresh(['plan']);
    }

    /**
     * Switch plans with proration: the unused portion of the current period
     * becomes OMNEX credit, and the billing period restarts on the new plan.
     */
    public function changePlan(Organization $organization, string $planSlug): Subscription
    {
        $plan = Plan::query()->where('slug', $planSlug)->where('active', true)->first()
            ?? throw ValidationException::withMessages(['plan' => ['The selected plan is not available.']]);

        $subscription = $this->currentSubscription($organization);

        if ($subscription === null || $subscription->status !== 'active') {
            throw ValidationException::withMessages(['plan' => ['Only an active subscription can change plan.']]);
        }

        if ($subscription->plan_id === $plan->id) {
            throw ValidationException::withMessages(['plan' => ['This organization is already subscribed to this plan.']]);
        }

        $proratedCredit = $this->proratedCredit($subscription);

        if ($proratedCredit > 0) {
            $this->addCredit($organization, $proratedCredit, 'proration', null, $subscription);
        }

        $subscription->update([
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'canceled_at' => null,
        ]);

        $organization->update(['plan_tier' => $plan->slug]);

        AuditLogger::record('subscription.plan_changed', 'subscription', $subscription->id, null, [
            'from' => $subscription->getOriginal('plan_id'),
            'to' => $plan->slug,
            'prorated_credit' => $proratedCredit,
        ]);

        $this->notifyOwners(
            $organization,
            'billing',
            'Plan changed',
            "Your plan is now {$plan->name}.",
            '/billing',
            'info',
        );

        return $subscription->fresh(['plan']);
    }

    public function currentSubscription(Organization $organization): ?Subscription
    {
        return Subscription::query()
            ->with('plan')
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['pending', 'active', 'past_due'])
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * @return array<int, Invoice>
     */
    public function invoices(Organization $organization): array
    {
        return Invoice::query()
            ->with('subscription.plan')
            ->where('organization_id', $organization->id)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /**
     * Spent breakdown by service, aggregated from the invoice history
     * (grouped by plan label). Powers the billing cockpit donut.
     *
     * @return array{total: int, currency: string, services: array<int, array{service: string, amount: int}>}
     */
    public function costBreakdown(Organization $organization): array
    {
        $invoices = Invoice::query()
            ->with('subscription.plan')
            ->where('organization_id', $organization->id)
            ->get();

        $currency = 'usd';
        $byService = [];
        foreach ($invoices as $invoice) {
            $service = $invoice->subscription?->plan?->name ?? 'One-time';
            $byService[$service] = ($byService[$service] ?? 0) + $invoice->amount_due;
            $currency = $invoice->currency;
        }

        $services = collect($byService)
            ->map(fn (int $amount, string $service) => ['service' => $service, 'amount' => $amount])
            ->sortByDesc('amount')
            ->values()
            ->all();

        return [
            'total' => (int) $invoices->sum('amount_due'),
            'currency' => $currency,
            'services' => $services,
        ];
    }

    /**
     * Validate a coupon code without redeeming it.
     *
     * @return array{code: string, name: string, discount_type: string, discount_value: int, discount: int}
     */
    public function validateCoupon(string $code): array
    {
        $coupon = $this->resolveCoupon($code);

        // A placeholder amount: the real discount depends on the chosen plan.
        $sample = 10000;

        return [
            'code' => $coupon->code,
            'name' => $coupon->name,
            'discount_type' => $coupon->discount_type,
            'discount_value' => $coupon->discount_value,
            'discount' => $coupon->discountFor($sample),
        ];
    }

    public function addCredit(Organization $organization, int $amount, string $reason, ?string $createdBy = null, ?Subscription $subscription = null): OrgCreditEntry
    {
        if ($amount === 0) {
            throw ValidationException::withMessages(['amount' => ['The credit amount must not be zero.']]);
        }

        $entry = OrgCreditEntry::create([
            'organization_id' => $organization->id,
            'subscription_id' => $subscription?->id,
            'amount' => $amount,
            'currency' => config('omnex.billing.currency', 'usd'),
            'reason' => $reason,
            'created_by' => $createdBy,
        ]);

        AuditLogger::record('credit.adjusted', 'organization', $organization->id, null, [
            'amount' => $amount,
            'reason' => $reason,
            'balance' => $this->creditBalance($organization),
        ]);

        return $entry;
    }

    public function creditBalance(Organization $organization): int
    {
        return (int) OrgCreditEntry::withoutTenancy()
            ->where('organization_id', $organization->id)
            ->sum('amount');
    }

    /**
     * @return Collection<int, OrgCreditEntry>
     */
    public function creditLedger(Organization $organization): Collection
    {
        return OrgCreditEntry::withoutTenancy()
            ->where('organization_id', $organization->id)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @return array<int, Coupon>
     */
    public function coupons(): array
    {
        return Coupon::query()
            ->withCount('redemptions')
            ->orderBy('code')
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCoupon(array $data): Coupon
    {
        $this->assertDiscountValue($data['discount_type'], (int) $data['discount_value']);

        $code = mb_strtoupper(trim((string) $data['code']));

        if (Coupon::query()->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => ['This coupon code already exists.']]);
        }

        $coupon = Coupon::create([
            'code' => $code,
            'name' => trim((string) $data['name']),
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
            'discount_type' => $data['discount_type'],
            'discount_value' => (int) $data['discount_value'],
            'currency' => $data['currency'] ?? config('omnex.billing.currency', 'usd'),
            'max_redemptions' => isset($data['max_redemptions']) ? (int) $data['max_redemptions'] : null,
            'expires_at' => isset($data['expires_at']) ? (string) $data['expires_at'] : null,
            'active' => isset($data['active']) ? (bool) $data['active'] : true,
        ]);

        AuditLogger::record('coupon.created', 'coupon', $coupon->id, null, [
            'code' => $coupon->code,
            'discount_type' => $coupon->discount_type,
            'discount_value' => $coupon->discount_value,
        ]);

        return $coupon;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCoupon(Coupon $coupon, array $data): Coupon
    {
        if (isset($data['discount_type'], $data['discount_value'])) {
            $this->assertDiscountValue($data['discount_type'], (int) $data['discount_value']);
        }

        $fill = array_intersect_key($data, array_flip([
            'name', 'description', 'discount_type', 'discount_value', 'currency',
            'max_redemptions', 'expires_at', 'active',
        ]));

        if (isset($fill['max_redemptions']) && $fill['max_redemptions'] !== null) {
            $fill['max_redemptions'] = (int) $fill['max_redemptions'];
        }
        if (isset($fill['active'])) {
            $fill['active'] = (bool) $fill['active'];
        }

        $coupon->update($fill);

        AuditLogger::record('coupon.updated', 'coupon', $coupon->id, null, [
            'code' => $coupon->code,
            'changes' => array_keys($fill),
        ]);

        return $coupon->fresh();
    }

    /**
     * @return Collection<int, CouponRedemption>
     */
    public function couponRedemptions(Coupon $coupon): Collection
    {
        return $coupon->redemptions()
            ->with('organization')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @param  'percent'|'amount'  $type
     */
    private function assertDiscountValue(string $type, int $value): void
    {
        if ($type === 'percent' && ($value < 1 || $value > 100)) {
            throw ValidationException::withMessages(['discount_value' => ['A percent discount must be between 1 and 100.']]);
        }

        if ($type === 'amount' && $value < 1) {
            throw ValidationException::withMessages(['discount_value' => ['An amount discount must be at least 1 cent.']]);
        }
    }

    /**
     * @return Collection<int, Subscription>
     */
    public function overdueSubscriptions(): Collection
    {
        return Subscription::withoutTenancy()
            ->with(['plan', 'coupon', 'organization'])
            ->where('status', 'active')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            // Provider-managed subscriptions (e.g. a live Stripe subscription
            // with its own billing engine) renew through provider webhooks;
            // OMNEX only rolls the period over for simulated/sandbox plans.
            ->whereNull('provider_subscription_id')
            ->get();
    }

    /**
     * Roll an expired sandbox subscription into its next billing period and
     * record the renewal invoice (coupon discount + available credits applied,
     * exactly like the activation path). Returns the new invoice.
     */
    public function renewSubscription(Subscription $subscription): Invoice
    {
        $plan = $subscription->plan;
        $number = $this->nextInvoiceNumber($subscription->organization_id);
        $currency = $plan->currency;

        $coupon = $subscription->coupon;
        $discount = $coupon !== null && $coupon->isValid() ? $coupon->discountFor($plan->price_monthly) : 0;

        $netAfterCoupon = max($plan->price_monthly - $discount, 0);
        $creditApplied = min($this->creditBalance($subscription->organization), $netAfterCoupon);
        $amountDue = $netAfterCoupon - $creditApplied;

        if ($creditApplied > 0) {
            $this->addCredit(
                $subscription->organization,
                -$creditApplied,
                'invoice:'.$number,
                null,
                $subscription,
            );
        }

        $periodStart = $subscription->current_period_end ?? now();
        $periodEnd = $periodStart->copy()->addMonth();

        $subscription->update([
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
            'canceled_at' => null,
        ]);

        $invoice = Invoice::create([
            'organization_id' => $subscription->organization_id,
            'subscription_id' => $subscription->id,
            'provider' => $subscription->provider,
            'provider_invoice_id' => null,
            'number' => $number,
            'amount' => $plan->price_monthly,
            'discount' => $discount,
            'credit_applied' => $creditApplied,
            'amount_due' => $amountDue,
            'currency' => $currency,
            'status' => 'paid',
            'paid_at' => now(),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        AuditLogger::record('subscription.renewed', 'subscription', $subscription->id, null, [
            'plan' => $plan->slug,
            'invoice' => $invoice->number,
            'amount_due' => $amountDue,
        ]);

        $this->notifyOwners(
            $subscription->organization,
            'billing',
            'Subscription renewed',
            "Your {$plan->name} subscription renewed for the next month.",
            '/billing',
            'info',
        );

        return $invoice;
    }

    private function resolveCoupon(string $code): Coupon
    {
        $coupon = Coupon::query()->where('code', mb_strtoupper(trim($code)))->first();

        if ($coupon === null) {
            throw ValidationException::withMessages(['coupon' => ['This coupon code does not exist.']]);
        }

        if (! $coupon->isValid()) {
            throw ValidationException::withMessages(['coupon' => ['This coupon is no longer valid.']]);
        }

        return $coupon;
    }

    private function resolveSubscription(PaymentWebhookEvent $event): ?Subscription
    {
        if ($event->checkoutId !== null) {
            $subscription = Subscription::withoutTenancy()->where('checkout_id', $event->checkoutId)->first();

            if ($subscription !== null) {
                return $subscription;
            }
        }

        if ($event->providerSubscriptionId !== null) {
            $subscription = Subscription::withoutTenancy()
                ->where('provider_subscription_id', $event->providerSubscriptionId)
                ->first();

            if ($subscription !== null) {
                return $subscription;
            }
        }

        if ($event->providerInvoiceId !== null) {
            $invoice = Invoice::withoutTenancy()->where('provider_invoice_id', $event->providerInvoiceId)->first();

            if ($invoice?->subscription_id !== null) {
                return Subscription::withoutTenancy()->find($invoice->subscription_id);
            }
        }

        return null;
    }

    private function succeed(Subscription $subscription, Plan $plan, PaymentWebhookEvent $event): Subscription
    {
        $subscription->update([
            'status' => 'active',
            'provider_subscription_id' => $event->providerSubscriptionId ?? $subscription->provider_subscription_id,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'canceled_at' => null,
        ]);

        $subscription->organization->update(['plan_tier' => $plan->slug]);

        $number = $this->nextInvoiceNumber($subscription->organization_id);
        $currency = $event->currency !== '' ? $event->currency : $plan->currency;

        $coupon = $subscription->coupon;
        $discount = $coupon !== null && $coupon->isValid() ? $coupon->discountFor($plan->price_monthly) : 0;

        $netAfterCoupon = max($plan->price_monthly - $discount, 0);
        $creditApplied = min($this->creditBalance($subscription->organization), $netAfterCoupon);
        $amountDue = $netAfterCoupon - $creditApplied;

        if ($discount > 0 && $coupon !== null) {
            CouponRedemption::create([
                'coupon_id' => $coupon->id,
                'organization_id' => $subscription->organization_id,
                'subscription_id' => $subscription->id,
                'discount_amount' => $discount,
                'currency' => $currency,
            ]);
            $coupon->increment('times_redeemed');
        }

        if ($creditApplied > 0) {
            $this->addCredit(
                $subscription->organization,
                -$creditApplied,
                'invoice:'.$number,
                null,
                $subscription,
            );
        }

        $invoice = Invoice::create([
            'organization_id' => $subscription->organization_id,
            'subscription_id' => $subscription->id,
            'provider' => $subscription->provider,
            'provider_invoice_id' => $event->providerInvoiceId,
            'number' => $number,
            'amount' => $plan->price_monthly,
            'discount' => $discount,
            'credit_applied' => $creditApplied,
            'amount_due' => $amountDue,
            'currency' => $currency,
            'status' => 'paid',
            'paid_at' => now(),
            'period_start' => now(),
            'period_end' => now()->addMonth(),
        ]);

        AuditLogger::record('subscription.activated', 'subscription', $subscription->id, null, [
            'plan' => $plan->slug,
            'invoice' => $invoice->number,
            'discount' => $discount,
            'credit_applied' => $creditApplied,
        ]);

        $this->notifyOwners(
            $subscription->organization,
            'billing',
            'Subscription activated',
            "Your {$plan->name} subscription is active.",
            '/billing',
            'success',
        );

        return $subscription->fresh(['plan', 'coupon']);
    }

    private function fail(Subscription $subscription, Plan $plan, PaymentWebhookEvent $event): Subscription
    {
        $subscription->update(['status' => 'past_due']);

        Invoice::create([
            'organization_id' => $subscription->organization_id,
            'subscription_id' => $subscription->id,
            'provider' => $subscription->provider,
            'provider_invoice_id' => $event->providerInvoiceId,
            'number' => $this->nextInvoiceNumber($subscription->organization_id),
            'amount' => $plan->price_monthly,
            'discount' => 0,
            'credit_applied' => 0,
            'amount_due' => $plan->price_monthly,
            'currency' => $event->currency !== '' ? $event->currency : $plan->currency,
            'status' => 'failed',
        ]);

        AuditLogger::record('subscription.payment_failed', 'subscription', $subscription->id, null, [
            'plan' => $plan->slug,
        ]);

        $this->notifyOwners(
            $subscription->organization,
            'billing',
            'Payment failed',
            "Payment for your {$plan->name} subscription failed.",
            '/billing',
            'warning',
        );

        return $subscription->fresh(['plan']);
    }

    /**
     * Credit (in cents) for the unused portion of the current billing period.
     */
    private function proratedCredit(Subscription $subscription): int
    {
        if ($subscription->current_period_start === null || $subscription->current_period_end === null) {
            return 0;
        }

        $totalSeconds = $subscription->current_period_end->getTimestamp() - $subscription->current_period_start->getTimestamp();
        $remainingSeconds = max(0, $subscription->current_period_end->getTimestamp() - now()->getTimestamp());

        if ($totalSeconds <= 0) {
            return 0;
        }

        $price = $subscription->plan->price_monthly;

        return (int) round($price * $remainingSeconds / $totalSeconds);
    }

    private function nextInvoiceNumber(string $organizationId): string
    {
        $count = Invoice::withoutTenancy()->where('organization_id', $organizationId)->count() + 1;

        return now()->format('Y').'-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }

    private function notifyOwners(Organization $organization, string $type, string $title, string $body, string $route, string $severity): void
    {
        Membership::withoutTenancy()
            ->where('organization_id', $organization->id)
            ->whereHas('role', fn ($query) => $query->where('key', 'owner'))
            ->with('user')
            ->get()
            ->each(fn (Membership $membership) => NotificationService::send(
                userId: $membership->user_id,
                type: $type,
                title: $title,
                body: $body,
                data: ['route' => $route],
                organizationId: $organization->id,
                severity: $severity,
            ));
    }
}
