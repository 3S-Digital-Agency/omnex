import { useState } from 'react';
import type { FormEvent } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import { useAuth } from '../../app/AuthProvider';
import { Button } from '../../components/ui/Button';
import { Field } from '../../components/ui/Field';
import { Input } from '../../components/ui/Input';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { AuthLayout } from './AuthLayout';

export function MfaVerifyPage() {
  const { verifyMfa, status } = useAuth();
  const { t } = useI18n();
  const navigate = useNavigate();
  const [code, setCode] = useState('');
  const [recoveryCode, setRecoveryCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  if (status !== 'mfa') return <Navigate to="/login" replace />;

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setLoading(true);
    try {
      await verifyMfa(code, recoveryCode);
      navigate('/');
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthLayout title={t('auth.mfaTitle')} subtitle={t('auth.mfaSubtitle')}>
      <form onSubmit={onSubmit} className="space-y-4">
        <Field label={t('auth.code6')} htmlFor="code">
          <Input
            id="code"
            inputMode="numeric"
            autoComplete="one-time-code"
            placeholder="123456"
            value={code}
            onChange={(e) => setCode(e.target.value)}
            disabled={recoveryCode.length > 0}
          />
        </Field>
        <Field label={t('auth.recoveryCode')} htmlFor="recovery">
          <Input
            id="recovery"
            placeholder="OMNEX-…"
            value={recoveryCode}
            onChange={(e) => setRecoveryCode(e.target.value)}
            disabled={code.length > 0}
          />
        </Field>
        {error ? (
          <div className="rounded-md border border-red-800 bg-red-950 px-3 py-2 text-sm text-red-200">{error}</div>
        ) : null}
        <Button type="submit" className="w-full" loading={loading}>
          {t('auth.verify')}
        </Button>
      </form>
    </AuthLayout>
  );
}
