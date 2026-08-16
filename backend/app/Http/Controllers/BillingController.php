<?php

namespace App\Http\Controllers;

use App\Http\Resources\CouponRedemptionResource;
use App\Http\Resources\CouponResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PlanResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Coupon;
use App\Models\Organization;
use App\Models\Subscription;
use App\Support\Billing\BillingService;
use App\Support\Billing\PaymentProviderException;
use App\Support\Billing\PaymentProviderRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(
        private BillingService $billing,
        private PaymentProviderRegistry $registry,
    ) {}

    public function providers(Request $request): JsonResponse
    {
        $this->authorize('billing.read');

        return response()->json(['data' => $this->billing->providers()]);
    }

    public function plans(Request $request): JsonResponse
    {
        $this->authorize('billing.read');

        return response()->json(['data' => PlanResource::collection($this->billing->plans())]);
    }

    public function subscription(Request $request): JsonResponse
    {
        $this->authorize('billing.read');

        $organization = $this->activeOrganization();
        $subscription = $this->billing->currentSubscription($organization);

        return response()->json([
            'data' => $subscription !== null ? new SubscriptionResource($subscription) : null,
        ]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $this->authorize('billing.read');

        return response()->json([
            'data' => InvoiceResource::collection($this->billing->invoices($this->activeOrganization())),
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $this->authorize('billing.manage');

        $data = $request->validate([
            'plan' => ['required', 'string', 'max:64'],
            'provider' => ['sometimes', 'string', 'max:32'],
            'coupon' => ['sometimes', 'string', 'max:64'],
        ]);

        try {
            $result = $this->billing->subscribe(
                $this->activeOrganization(),
                $data['plan'],
                $data['provider'] ?? null,
                $data['coupon'] ?? null,
            );
        } catch (PaymentProviderException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json([
            'subscription' => new SubscriptionResource($result['subscription']),
            'checkout_url' => $result['checkout_url'],
        ], 201);
    }

    public function validateCoupon(Request $request): JsonResponse
    {
        $this->authorize('billing.read');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        return response()->json(['data' => $this->billing->validateCoupon($data['code'])]);
    }

    public function coupons(Request $request): JsonResponse
    {
        $this->authorize('billing.read');

        return response()->json(['data' => CouponResource::collection($this->billing->coupons())]);
    }

    public function storeCoupon(Request $request): JsonResponse
    {
        $this->authorize('billing.manage');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'unique:coupons,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'discount_type' => ['required', 'in:percent,amount'],
            'discount_value' => ['required', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'max_redemptions' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'active' => ['sometimes', 'boolean'],
        ]);

        return response()->json(new CouponResource($this->billing->createCoupon($data)), 201);
    }

    public function updateCoupon(Request $request, Coupon $coupon): JsonResponse
    {
        $this->authorize('billing.manage');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'discount_type' => ['sometimes', 'in:percent,amount'],
            'discount_value' => ['sometimes', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'max_redemptions' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'active' => ['sometimes', 'boolean'],
        ]);

        return response()->json(new CouponResource($this->billing->updateCoupon($coupon, $data)));
    }

    public function couponRedemptions(Request $request, Coupon $coupon): JsonResponse
    {
        $this->authorize('billing.read');

        return response()->json([
            'data' => CouponRedemptionResource::collection($this->billing->couponRedemptions($coupon)),
        ]);
    }

    public function changePlan(Request $request): JsonResponse
    {
        $this->authorize('billing.manage');

        $data = $request->validate([
            'plan' => ['required', 'string', 'max:64'],
        ]);

        return response()->json(new SubscriptionResource(
            $this->billing->changePlan($this->activeOrganization(), $data['plan'])
        ));
    }

    public function credits(Request $request): JsonResponse
    {
        $this->authorize('billing.read');

        $organization = $this->activeOrganization();

        return response()->json([
            'data' => [
                'balance' => $this->billing->creditBalance($organization),
                'entries' => $this->billing->creditLedger($organization)
                    ->map(fn ($entry) => [
                        'id' => $entry->id,
                        'amount' => $entry->amount,
                        'currency' => $entry->currency,
                        'reason' => $entry->reason,
                        'created_at' => $entry->created_at?->toIso8601String(),
                    ])
                    ->values(),
            ],
        ]);
    }

    public function addCredits(Request $request): JsonResponse
    {
        $this->authorize('billing.manage');

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reason' => ['required', 'string', 'max:64'],
        ]);

        $entry = $this->billing->addCredit(
            $this->activeOrganization(),
            (int) $data['amount'],
            $data['reason'],
            $request->user()?->id,
        );

        return response()->json([
            'data' => [
                'id' => $entry->id,
                'amount' => $entry->amount,
                'currency' => $entry->currency,
                'reason' => $entry->reason,
                'created_at' => $entry->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function cancel(Request $request, string $subscription): JsonResponse
    {
        $this->authorize('billing.manage');

        return response()->json(new SubscriptionResource(
            $this->billing->cancel(Subscription::findOrFail($subscription))
        ));
    }

    /**
     * Public webhook endpoint (no auth, no tenant): the provider calls back
     * without an OMNEX session. The tenant is resolved from the event itself.
     */
    public function webhook(Request $request, string $provider): JsonResponse
    {
        try {
            $adapter = $this->registry->get($provider);
            $event = $adapter->verifyWebhook(
                (string) $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
            );
        } catch (PaymentProviderException|\InvalidArgumentException $e) {
            abort(400, $e->getMessage());
        }

        $this->billing->handleWebhook($event);

        return response()->json(['received' => true]);
    }

    private function activeOrganization(): Organization
    {
        return app(TenantContext::class)->organization()
            ?? abort(403, 'No active organization.');
    }
}
