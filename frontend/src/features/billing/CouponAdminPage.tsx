import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Plus, Tag, Users } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import type { CouponAdminDto, CouponRedemptionDto } from '../../lib/api/types';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { Card, CardHeader } from '../../components/ui/Card';
import { EmptyState, Spinner } from '../../components/ui/Spinner';
import { useToast } from '../../components/ui/Toast';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { formatDate } from '../../lib/utils';

function formatMoney(cents: number, currency: string): string {
  const amount = (cents / 100).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
  return `${amount} ${currency.toUpperCase()}`;
}

function formatDiscount(coupon: CouponAdminDto): string {
  if (coupon.discount_type === 'percent') return `${coupon.discount_value}%`;
  return formatMoney(coupon.discount_value, coupon.currency);
}

export function CouponAdminPage() {
  const { hasPermission } = useAuth();
  const { t } = useI18n();
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const canManage = hasPermission('billing.manage');

  const coupons = useQuery({ queryKey: ['billing', 'coupons'], queryFn: () => api.listCoupons() });
  const [redemptionsFor, setRedemptionsFor] = useState<CouponAdminDto | null>(null);
  const redemptions = useQuery({
    queryKey: ['billing', 'coupons', redemptionsFor?.id, 'redemptions'],
    queryFn: () => api.listCouponRedemptions(redemptionsFor!.id),
    enabled: !!redemptionsFor,
  });

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['billing', 'coupons'] });
  };

  const create = useMutation({
    mutationFn: (input: Parameters<typeof api.createCoupon>[0]) => api.createCoupon(input),
    onSuccess: () => {
      invalidate();
      toast(t('toast.billing.couponCreated'), 'success');
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const update = useMutation({
    mutationFn: (input: { id: string; active: boolean }) => api.updateCoupon(input.id, { active: input.active }),
    onSuccess: () => {
      invalidate();
      toast(t('toast.billing.couponUpdated'), 'success');
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <header className="flex items-center gap-3">
        <Link to="/billing" className="flex h-9 w-9 items-center justify-center rounded-lg border border-edge bg-panel text-zinc-400 transition hover:text-white">
          <ArrowLeft className="h-4 w-4" />
        </Link>
        <div className="flex h-12 w-12 items-center justify-center rounded-xl border border-edge bg-panel">
          <Tag className="h-6 w-6 text-brand-400" />
        </div>
        <div>
          <h1 className="text-2xl font-bold text-white">{t('billing.coupons')}</h1>
          <p className="text-sm text-zinc-400">{t('billing.couponsAdminSubtitle')}</p>
        </div>
      </header>

      {canManage ? <CreateCouponForm loading={create.isPending} onSubmit={(input) => create.mutate(input)} /> : null}

      <Card>
        <CardHeader title={t('billing.couponsList')} description={t('billing.couponsListDescription')} />
        {coupons.isLoading ? (
          <div className="flex justify-center py-8">
            <Spinner />
          </div>
        ) : coupons.data && coupons.data.length > 0 ? (
          <ul className="divide-y divide-edge">
            {coupons.data.map((coupon) => (
              <li key={coupon.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <p className="text-sm font-semibold text-white">{coupon.code}</p>
                    <Badge tone={coupon.active ? 'success' : 'neutral'}>{coupon.active ? t('billing.couponActiveBadge') : t('billing.couponInactiveBadge')}</Badge>
                    {coupon.expires_at ? <Badge tone="warning">{t('billing.couponExpires', { date: formatDate(coupon.expires_at) })}</Badge> : null}
                  </div>
                  <p className="mt-0.5 text-xs text-zinc-500">
                    {coupon.name} · {formatDiscount(coupon)}
                    {coupon.max_redemptions !== null ? ` · ${coupon.times_redeemed}/${coupon.max_redemptions}` : ` · ${coupon.times_redeemed}`}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => {
                      setRedemptionsFor(redemptionsFor?.id === coupon.id ? null : coupon);
                    }}
                  >
                    <Users className="mr-1.5 h-3.5 w-3.5" />
                    {t('billing.couponRedemptions')}
                  </Button>
                  {canManage ? (
                    <Button
                      variant="outline"
                      size="sm"
                      loading={update.isPending}
                      onClick={() => update.mutate({ id: coupon.id, active: !coupon.active })}
                    >
                      {coupon.active ? t('billing.couponDeactivate') : t('billing.couponActivate')}
                    </Button>
                  ) : null}
                </div>
              </li>
            ))}
          </ul>
        ) : (
          <div className="p-5">
            <EmptyState title={t('billing.noCoupons')} description={t('billing.noCouponsDescription')} />
          </div>
        )}
      </Card>

      {redemptionsFor ? <RedemptionsPanel coupon={redemptionsFor} loading={redemptions.isLoading} data={redemptions.data ?? []} /> : null}
    </div>
  );
}

function CreateCouponForm({ loading, onSubmit }: { loading: boolean; onSubmit: (input: Parameters<typeof api.createCoupon>[0]) => void }) {
  const { t } = useI18n();
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [type, setType] = useState<'percent' | 'amount'>('percent');
  const [value, setValue] = useState('');
  const [maxRedemptions, setMaxRedemptions] = useState('');
  const [expiresAt, setExpiresAt] = useState('');

  const submit = () => {
    const discountValue = Math.round(parseFloat(value) * (type === 'amount' ? 100 : 1));
    if (!code.trim() || !name.trim() || !Number.isFinite(discountValue) || discountValue <= 0) return;
    onSubmit({
      code,
      name,
      discount_type: type,
      discount_value: discountValue,
      max_redemptions: maxRedemptions.trim() !== '' ? Math.max(1, parseInt(maxRedemptions, 10) || 0) : null,
      expires_at: expiresAt ? new Date(expiresAt).toISOString() : null,
    });
    setCode('');
    setName('');
    setValue('');
    setMaxRedemptions('');
    setExpiresAt('');
  };

  const inputClass =
    'h-9 rounded-md border border-edge bg-panel px-2 text-sm text-white placeholder:text-zinc-600 focus:outline-none focus:ring-1 focus:ring-brand-500';

  return (
    <Card>
      <CardHeader title={t('billing.couponCreate')} description={t('billing.couponCreateDescription')} />
      <div className="flex flex-wrap items-end gap-2 px-5 py-4">
        <label className="flex flex-col gap-1 text-xs text-zinc-500">
          {t('billing.couponCodeLabel')}
          <input value={code} onChange={(event) => setCode(event.target.value.toUpperCase())} placeholder="SUMMER20" className={`${inputClass} w-28`} />
        </label>
        <label className="flex flex-col gap-1 text-xs text-zinc-500">
          {t('billing.couponNameLabel')}
          <input value={name} onChange={(event) => setName(event.target.value)} placeholder={t('billing.couponNamePlaceholder')} className={`${inputClass} w-36`} />
        </label>
        <label className="flex flex-col gap-1 text-xs text-zinc-500">
          {t('billing.couponTypeLabel')}
          <select value={type} onChange={(event) => setType(event.target.value as 'percent' | 'amount')} className={`${inputClass} w-24`}>
            <option value="percent">%</option>
            <option value="amount">$</option>
          </select>
        </label>
        <label className="flex flex-col gap-1 text-xs text-zinc-500">
          {t('billing.couponValueLabel')}
          <input value={value} onChange={(event) => setValue(event.target.value)} type="number" min="0" step={type === 'amount' ? '0.01' : '1'} className={`${inputClass} w-20`} />
        </label>
        <label className="flex flex-col gap-1 text-xs text-zinc-500">
          {t('billing.couponMaxLabel')}
          <input value={maxRedemptions} onChange={(event) => setMaxRedemptions(event.target.value)} type="number" min="1" placeholder="∞" className={`${inputClass} w-20`} />
        </label>
        <label className="flex flex-col gap-1 text-xs text-zinc-500">
          {t('billing.couponExpiresLabel')}
          <input value={expiresAt} onChange={(event) => setExpiresAt(event.target.value)} type="date" className={`${inputClass} w-36`} />
        </label>
        <Button size="sm" loading={loading} disabled={!code.trim() || !name.trim() || !value || parseFloat(value) <= 0} onClick={submit}>
          <Plus className="mr-1.5 h-3.5 w-3.5" />
          {t('billing.couponCreate')}
        </Button>
      </div>
    </Card>
  );
}

function RedemptionsPanel({ coupon, loading, data }: { coupon: CouponAdminDto; loading: boolean; data: CouponRedemptionDto[] }) {
  const { t } = useI18n();

  return (
    <Card>
      <CardHeader title={t('billing.couponRedemptionsFor', { code: coupon.code })} description={t('billing.couponRedemptionsDescription')} />
      {loading ? (
        <div className="flex justify-center py-8">
          <Spinner />
        </div>
      ) : data.length > 0 ? (
        <ul className="divide-y divide-edge">
          {data.map((redemption) => (
            <li key={redemption.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
              <div className="min-w-0">
                <p className="text-sm font-medium text-white">{redemption.organization_name ?? t('billing.couponUnknownOrg')}</p>
                <p className="text-xs text-zinc-500">{formatDate(redemption.created_at)}</p>
              </div>
              <p className="text-sm font-semibold text-emerald-400">−{formatMoney(redemption.discount_amount, redemption.currency)}</p>
            </li>
          ))}
        </ul>
      ) : (
        <div className="p-5">
          <EmptyState title={t('billing.noRedemptions')} description={t('billing.noRedemptionsDescription')} />
        </div>
      )}
    </Card>
  );
}
