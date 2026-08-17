import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, CreditCard, Settings2, Tag, Wallet } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import type { BillingCostServiceDto, BillingPlanDto, CouponDto, InvoiceDto, SubscriptionDto } from '../../lib/api/types';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { Card, CardHeader } from '../../components/ui/Card';
import { AreaChart } from '../../components/viz/AreaChart';
import { DistributionDonut } from '../../components/viz/DistributionDonut';
import { Donut } from '../../components/viz/Donut';
import { useAnimatedNumber } from '../../components/viz/useMotion';
import { EmptyState, Spinner } from '../../components/ui/Spinner';
import { useToast } from '../../components/ui/Toast';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { cn, formatDate } from '../../lib/utils';

function formatMoney(cents: number, currency: string): string {
  const amount = (cents / 100).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
  return `${amount} ${currency.toUpperCase()}`;
}

const STATUS_TONE: Record<SubscriptionDto['status'], 'neutral' | 'success' | 'warning' | 'danger'> = {
  pending: 'neutral',
  active: 'success',
  past_due: 'danger',
  trialing: 'warning',
  canceled: 'neutral',
};

const INVOICE_TONE: Record<InvoiceDto['status'], 'neutral' | 'success' | 'danger'> = {
  open: 'neutral',
  paid: 'success',
  failed: 'danger',
  void: 'neutral',
};

export function BillingPage() {
  const { activeOrganization, hasPermission } = useAuth();
  const { t } = useI18n();
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const orgId = activeOrganization?.id;
  const canManage = hasPermission('billing.manage');

  const plans = useQuery({ queryKey: ['billing', 'plans', orgId], queryFn: () => api.listBillingPlans(), enabled: !!orgId });
  const subscription = useQuery({
    queryKey: ['billing', 'subscription', orgId],
    queryFn: () => api.getSubscription(),
    enabled: !!orgId,
  });
  const invoices = useQuery({ queryKey: ['billing', 'invoices', orgId], queryFn: () => api.listInvoices(), enabled: !!orgId });
  const credits = useQuery({ queryKey: ['billing', 'credits', orgId], queryFn: () => api.getCredits(), enabled: !!orgId });
  const breakdown = useQuery({
    queryKey: ['billing', 'breakdown', orgId],
    queryFn: () => api.getBillingCostBreakdown(),
    enabled: !!orgId,
  });

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['billing'] });
  };

  const [couponCode, setCouponCode] = useState('');
  const [couponInfo, setCouponInfo] = useState<CouponDto | null>(null);
  const [couponError, setCouponError] = useState<string | null>(null);

  const validateCoupon = useMutation({
    mutationFn: (code: string) => api.validateCoupon(code),
    onSuccess: (coupon) => {
      setCouponInfo(coupon);
      setCouponError(null);
      toast(t('toast.billing.couponValid', { name: coupon.name }), 'success');
    },
    onError: (err) => {
      setCouponInfo(null);
      setCouponError(errorMessage(err));
    },
  });

  const subscribe = useMutation({
    mutationFn: (plan: string) => api.subscribeToPlan(plan, undefined, couponInfo?.code),
    onSuccess: () => {
      invalidate();
      setCouponInfo(null);
      setCouponCode('');
      toast(t('toast.billing.subscribed'), 'success');
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const changePlan = useMutation({
    mutationFn: (plan: string) => api.changePlan(plan),
    onSuccess: () => {
      invalidate();
      toast(t('toast.billing.planChanged'), 'success');
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const cancel = useMutation({
    mutationFn: (id: string) => api.cancelSubscription(id),
    onSuccess: () => {
      invalidate();
      toast(t('toast.billing.canceled'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const addCredits = useMutation({
    mutationFn: (input: { amount: number; reason: string }) => api.addCredits(input.amount, input.reason),
    onSuccess: () => {
      invalidate();
      toast(t('toast.billing.creditsAdded'), 'success');
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const current = subscription.data;
  const currentPlanSlug = current?.plan?.slug ?? activeOrganization?.plan_tier ?? 'free';
  const otherPlans = (plans.data ?? []).filter((p) => p.slug !== currentPlanSlug && p.slug !== 'free');

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <header className="flex items-center gap-3">
        <div className="flex h-12 w-12 items-center justify-center rounded-xl border border-edge bg-panel">
          <CreditCard className="h-6 w-6 text-brand-400" />
        </div>
        <div>
          <h1 className="text-2xl font-bold text-white">{t('billing.title')}</h1>
          <p className="text-sm text-zinc-400">{t('billing.subtitle', { name: activeOrganization?.name ?? '' })}</p>
        </div>
        {hasPermission('billing.read') ? (
          <Link
            to="/billing/coupons"
            className="ml-auto inline-flex h-9 items-center gap-2 rounded-md border border-edge bg-panel px-3 text-sm text-zinc-300 transition hover:border-brand-700 hover:text-white"
          >
            <Settings2 className="h-3.5 w-3.5" />
            {t('billing.manageCoupons')}
          </Link>
        ) : null}
      </header>

      <BillingCockpit
        breakdown={breakdown.data ?? { total: 0, currency: 'usd', services: [] }}
        invoices={invoices.data ?? []}
        creditBalance={credits.data?.balance ?? 0}
        isLoading={breakdown.isLoading || invoices.isLoading || credits.isLoading}
        t={t}
        formatMoney={formatMoney}
      />

      <Card>
        <CardHeader title={t('billing.currentPlan')} description={t('billing.currentPlan')} />
        {subscription.isLoading ? (
          <div className="flex justify-center py-8">
            <Spinner />
          </div>
        ) : current ? (
          <div className="space-y-4 px-5 py-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="flex items-center gap-3">
                <p className="text-lg font-semibold text-white">{current.plan?.name}</p>
                <Badge tone={STATUS_TONE[current.status]}>{t(`billing.status.${current.status}`)}</Badge>
                {current.coupon ? (
                  <Badge tone="brand" className="gap-1">
                    <Tag className="h-3 w-3" />
                    {current.coupon.code}
                  </Badge>
                ) : null}
              </div>
              <div className="flex items-center gap-4">
                <p className="text-xs text-zinc-500">
                  {current.current_period_end ? t('billing.renewsAt', { date: formatDate(current.current_period_end) }) : ''}
                </p>
                {canManage && current.status !== 'canceled' ? (
                  <Button
                    variant="outline"
                    size="sm"
                    loading={cancel.isPending}
                    onClick={() => {
                      if (window.confirm(t('billing.cancelConfirm'))) cancel.mutate(current.id);
                    }}
                  >
                    {t('billing.cancel')}
                  </Button>
                ) : null}
              </div>
            </div>

            {canManage && current.status === 'active' && otherPlans.length > 0 ? (
              <div className="flex flex-wrap items-center gap-2 rounded-lg border border-edge bg-panel/50 px-3 py-2.5">
                <span className="text-xs text-zinc-400">{t('billing.changePlanLabel')}</span>
                <select
                  className="rounded-md border border-edge bg-panel px-2 py-1 text-sm text-white focus:outline-none focus:ring-1 focus:ring-brand-500"
                  value=""
                  onChange={(event) => {
                    if (event.target.value) changePlan.mutate(event.target.value);
                  }}
                >
                  <option value="" disabled>
                    {t('billing.selectPlan')}
                  </option>
                  {otherPlans.map((plan) => (
                    <option key={plan.slug} value={plan.slug}>
                      {plan.name} — {plan.price_monthly === 0 ? t('billing.free') : formatMoney(plan.price_monthly, plan.currency)}
                    </option>
                  ))}
                </select>
                <span className="text-xs text-zinc-500">{t('billing.prorationHint')}</span>
              </div>
            ) : null}
          </div>
        ) : (
          <div className="px-5 py-4">
            <EmptyState title={t('billing.noSubscription')} description={t('billing.noSubscriptionDescription')} />
          </div>
        )}
      </Card>

      <section>
        <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 className="text-sm font-semibold uppercase tracking-wider text-zinc-400">{t('billing.plans')}</h2>
            <p className="text-sm text-zinc-500">{t('billing.plansDescription')}</p>
          </div>
          {canManage ? (
            <div className="flex items-center gap-2">
              <div className="relative">
                <Tag className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-zinc-500" />
                <input
                  value={couponCode}
                  onChange={(event) => {
                    setCouponCode(event.target.value.toUpperCase());
                    setCouponInfo(null);
                    setCouponError(null);
                  }}
                  placeholder={t('billing.couponPlaceholder')}
                  className="h-9 w-40 rounded-md border border-edge bg-panel pl-8 pr-2 text-sm text-white placeholder:text-zinc-600 focus:outline-none focus:ring-1 focus:ring-brand-500"
                />
              </div>
              <Button
                variant="outline"
                size="sm"
                loading={validateCoupon.isPending}
                disabled={couponCode.trim() === ''}
                onClick={() => validateCoupon.mutate(couponCode)}
              >
                {t('billing.applyCoupon')}
              </Button>
              {couponInfo ? (
                <Badge tone="success">
                  {t('billing.couponActive', { name: couponInfo.name, value: couponInfo.discount_type === 'percent' ? `${couponInfo.discount_value}%` : formatMoney(couponInfo.discount_value, 'usd') })}
                </Badge>
              ) : null}
              {couponError ? <span className="text-xs text-red-400">{couponError}</span> : null}
            </div>
          ) : null}
        </div>

        {plans.isLoading ? (
          <div className="flex justify-center py-8">
            <Spinner />
          </div>
        ) : (
          <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {(plans.data ?? []).map((plan) => (
              <PlanCard
                key={plan.id}
                plan={plan}
                isCurrent={plan.slug === currentPlanSlug && !(current && current.status === 'canceled')}
                canManage={canManage}
                busy={subscribe.isPending}
                onSubscribe={() => subscribe.mutate(plan.slug)}
              />
            ))}
          </div>
        )}
      </section>

      <Card>
        <CardHeader title={t('billing.credits')} description={t('billing.creditsDescription')} />
        {credits.isLoading ? (
          <div className="flex justify-center py-8">
            <Spinner />
          </div>
        ) : (
          <div className="space-y-4 px-5 py-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="flex items-center gap-3">
                <div className="flex h-9 w-9 items-center justify-center rounded-lg border border-edge bg-panel">
                  <Wallet className="h-4 w-4 text-brand-400" />
                </div>
                <div>
                  <p className="text-xs text-zinc-500">{t('billing.creditBalance')}</p>
                  <p className="text-lg font-semibold text-white">{formatMoney(credits.data?.balance ?? 0, 'usd')}</p>
                </div>
              </div>
              {canManage ? (
                <AddCreditsForm loading={addCredits.isPending} onSubmit={(amount, reason) => addCredits.mutate({ amount, reason })} />
              ) : null}
            </div>

            {credits.data && credits.data.entries.length > 0 ? (
              <ul className="divide-y divide-edge">
                {credits.data.entries.slice(0, 10).map((entry) => (
                  <li key={entry.id} className="flex items-center justify-between gap-3 py-2.5">
                    <div className="min-w-0">
                      <p className="truncate text-sm text-zinc-300">{entry.reason}</p>
                      <p className="text-xs text-zinc-500">{formatDate(entry.created_at)}</p>
                    </div>
                    <p className={cn('text-sm font-semibold', entry.amount >= 0 ? 'text-emerald-400' : 'text-red-400')}>
                      {entry.amount >= 0 ? '+' : ''}
                      {formatMoney(entry.amount, entry.currency)}
                    </p>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-zinc-500">{t('billing.noCredits')}</p>
            )}
          </div>
        )}
      </Card>

      <Card>
        <CardHeader title={t('billing.invoices')} description={t('billing.invoicesDescription')} />
        {invoices.isLoading ? (
          <div className="flex justify-center py-8">
            <Spinner />
          </div>
        ) : invoices.data && invoices.data.length > 0 ? (
          <ul className="divide-y divide-edge">
            {invoices.data.map((invoice) => (
              <InvoiceRow key={invoice.id} invoice={invoice} />
            ))}
          </ul>
        ) : (
          <div className="p-5">
            <EmptyState title={t('billing.noInvoices')} description={t('billing.noInvoicesDescription')} />
          </div>
        )}
      </Card>
    </div>
  );
}

const SERVICE_TONES: Record<string, string> = {
  Free: 'bg-zinc-500',
  Starter: 'bg-emerald-400',
  Pro: 'bg-brand-500',
  Business: 'bg-amber-400',
};

const SERVICE_COLORS: Record<string, string> = {
  Free: 'text-zinc-400',
  Starter: 'text-emerald-400',
  Pro: 'text-brand-400',
  Business: 'text-amber-400',
};

function BillingCockpit({
  breakdown,
  invoices,
  creditBalance,
  isLoading,
  t,
  formatMoney,
}: {
  breakdown: { total: number; currency: string; services: BillingCostServiceDto[] };
  invoices: InvoiceDto[];
  creditBalance: number;
  isLoading: boolean;
  t: (key: string, params?: Record<string, string | number>) => string;
  formatMoney: (cents: number, currency: string) => string;
}) {
  const creditPercent = Math.min(100, (creditBalance / 10000) * 100);
  const animated = Math.round(useAnimatedNumber(creditPercent));

  // Spending timeline: cumulative invoice amounts, oldest first.
  const chronological = [...invoices].sort((a, b) =>
    (a.created_at ?? '').localeCompare(b.created_at ?? ''),
  );
  const cumulative: number[] = [];
  let running = 0;
  for (const invoice of chronological) {
    running += invoice.amount_due;
    cumulative.push(running);
  }

  const services = breakdown.services;
  const maxCredit = 10000;

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
      {/* Credit gauge */}
      <Card className="flex flex-col items-center justify-center p-6 text-center">
        <p className="text-sm text-zinc-400">{t('billing.cockpit.creditTitle')}</p>
        <div className="mt-3">
          <Donut
            value={creditPercent}
            size={148}
            thickness={12}
            className={creditPercent >= 90 ? 'text-red-400' : creditPercent >= 70 ? 'text-amber-400' : 'text-emerald-400'}
            label={`${t('billing.cockpit.creditTitle')}: ${Math.round(creditPercent)}%`}
          >
            <span className="text-2xl font-bold text-white">{animated}%</span>
          </Donut>
        </div>
        <p className="mt-3 text-xs text-zinc-500">
          {formatMoney(creditBalance, 'usd')} / {formatMoney(maxCredit, 'usd')}
        </p>
      </Card>

      {/* Spending timeline */}
      <Card className="lg:col-span-2">
        <CardHeader title={t('billing.cockpit.timelineTitle')} description={t('billing.cockpit.timelineDescription')} />
        {isLoading ? (
          <div className="flex justify-center py-10">
            <Spinner />
          </div>
        ) : cumulative.length > 1 ? (
          <div className="px-5 pb-4 pt-2">
            <div className="text-brand-400">
              <AreaChart
                values={cumulative}
                height={110}
                className="text-brand-400"
                label={`${t('billing.cockpit.timelineTitle')}: ${cumulative.join(', ')}`}
              />
            </div>
            <div className="mt-2 flex items-center justify-between text-[11px] text-zinc-500">
              <span>{chronological[0].created_at ? formatDate(chronological[0].created_at) : '—'}</span>
              <span className="font-mono">{formatMoney(running, breakdown.currency)}</span>
              <span>
                {chronological[chronological.length - 1].created_at ? formatDate(chronological[chronological.length - 1].created_at) : '—'}
              </span>
            </div>
          </div>
        ) : (
          <div className="p-5">
            <EmptyState title={t('billing.cockpit.timelineEmpty')} />
          </div>
        )}
      </Card>

      {/* Cost breakdown by service */}
      <Card className="lg:col-span-3">
        <CardHeader title={t('billing.cockpit.breakdownTitle')} description={t('billing.cockpit.breakdownDescription')} />
        {isLoading ? (
          <div className="flex justify-center py-8">
            <Spinner />
          </div>
        ) : services.length > 0 ? (
          <div className="flex flex-wrap items-center justify-center gap-8 px-5 pb-5">
            <DistributionDonut
              segments={services.map((service) => ({
                value: service.amount,
                color: SERVICE_COLORS[service.service] ?? 'text-zinc-400',
                label: service.service,
              }))}
              size={132}
              thickness={11}
              center={
                <div className="text-center">
                  <span className="block text-xl font-bold text-white">{formatMoney(breakdown.total, breakdown.currency)}</span>
                  <span className="block text-[10px] uppercase tracking-wider text-zinc-500">{t('billing.cockpit.spent')}</span>
                </div>
              }
              label={t('billing.cockpit.breakdownTitle')}
            />
            <ul className="space-y-2 text-xs">
              {services.map((service) => (
                <li key={service.service} className="flex items-center gap-2">
                  <span
                    className={cn('h-2.5 w-2.5 rounded-sm', SERVICE_TONES[service.service] ?? 'bg-zinc-500')}
                    aria-hidden="true"
                  />
                  <span className="text-zinc-400">{service.service}</span>
                  <span className="ml-auto font-mono font-semibold text-white">{formatMoney(service.amount, breakdown.currency)}</span>
                </li>
              ))}
            </ul>
          </div>
        ) : (
          <div className="p-5">
            <EmptyState title={t('billing.cockpit.breakdownEmpty')} />
          </div>
        )}
      </Card>
    </div>
  );
}

function AddCreditsForm({ loading, onSubmit }: { loading: boolean; onSubmit: (amount: number, reason: string) => void }) {
  const { t } = useI18n();
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');

  const submit = () => {
    const cents = Math.round(parseFloat(amount) * 100);
    if (!Number.isFinite(cents) || cents <= 0) return;
    onSubmit(cents, reason.trim() || t('billing.creditReasonDefault'));
    setAmount('');
    setReason('');
  };

  return (
    <div className="flex flex-wrap items-center gap-2">
      <input
        value={amount}
        onChange={(event) => setAmount(event.target.value)}
        type="number"
        min="0"
        step="0.01"
        placeholder={t('billing.creditAmount')}
        className="h-9 w-24 rounded-md border border-edge bg-panel px-2 text-sm text-white placeholder:text-zinc-600 focus:outline-none focus:ring-1 focus:ring-brand-500"
      />
      <input
        value={reason}
        onChange={(event) => setReason(event.target.value)}
        placeholder={t('billing.creditReason')}
        className="h-9 w-40 rounded-md border border-edge bg-panel px-2 text-sm text-white placeholder:text-zinc-600 focus:outline-none focus:ring-1 focus:ring-brand-500"
      />
      <Button size="sm" loading={loading} disabled={!amount || parseFloat(amount) <= 0} onClick={submit}>
        {t('billing.addCredits')}
      </Button>
    </div>
  );
}

function PlanCard({
  plan,
  isCurrent,
  canManage,
  busy,
  onSubscribe,
}: {
  plan: BillingPlanDto;
  isCurrent: boolean;
  canManage: boolean;
  busy: boolean;
  onSubscribe: () => void;
}) {
  const { t } = useI18n();

  return (
    <div
      className={cn(
        'flex flex-col rounded-xl border bg-panel p-5',
        isCurrent ? 'border-brand-700' : 'border-edge',
      )}
    >
      <p className="text-base font-semibold text-white">{plan.name}</p>
      <p className="mt-1 min-h-8 text-xs text-zinc-500">{plan.description}</p>
      <p className="mt-3 text-2xl font-bold text-white">
        {plan.price_monthly === 0 ? t('billing.free') : formatMoney(plan.price_monthly, plan.currency)}
        {plan.price_monthly > 0 ? <span className="text-sm font-normal text-zinc-500">{t('billing.perMonth')}</span> : null}
      </p>

      <ul className="mt-4 flex-1 space-y-2">
        {plan.features.map((feature) => (
          <li key={feature} className="flex items-center gap-2 text-sm text-zinc-300">
            <Check className="h-3.5 w-3.5 shrink-0 text-brand-400" />
            {feature}
          </li>
        ))}
      </ul>

      {isCurrent ? (
        <Badge tone="brand" className="mt-4 justify-center">
          {t('billing.current')}
        </Badge>
      ) : canManage ? (
        <Button className="mt-4 w-full" loading={busy} disabled={plan.slug === 'free'} onClick={onSubscribe}>
          {t('billing.subscribe')}
        </Button>
      ) : null}
    </div>
  );
}

function InvoiceRow({ invoice }: { invoice: InvoiceDto }) {
  const { t } = useI18n();

  return (
    <li className="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
      <div className="min-w-0">
        <p className="text-sm font-medium text-white">{invoice.number}</p>
        <p className="text-xs text-zinc-500">
          {invoice.plan?.name ?? invoice.provider} · {t('billing.paidAt', { date: formatDate(invoice.paid_at) })}
        </p>
        {(invoice.discount > 0 || invoice.credit_applied > 0) && invoice.amount_due !== invoice.amount ? (
          <p className="mt-0.5 text-xs text-zinc-500">
            {invoice.discount > 0 ? `${t('billing.invoice.discount')} −${formatMoney(invoice.discount, invoice.currency)}` : null}
            {invoice.discount > 0 && invoice.credit_applied > 0 ? ' · ' : null}
            {invoice.credit_applied > 0 ? `${t('billing.invoice.credit')} −${formatMoney(invoice.credit_applied, invoice.currency)}` : null}
          </p>
        ) : null}
      </div>
      <div className="flex shrink-0 items-center gap-3">
        <div className="text-right">
          {invoice.discount > 0 || invoice.credit_applied > 0 ? (
            <p className="text-xs text-zinc-500 line-through">{formatMoney(invoice.amount, invoice.currency)}</p>
          ) : null}
          <p className="text-sm font-semibold text-white">{formatMoney(invoice.amount_due, invoice.currency)}</p>
        </div>
        <Badge tone={INVOICE_TONE[invoice.status]}>{t(`billing.invoice.status.${invoice.status}`)}</Badge>
      </div>
    </li>
  );
}
