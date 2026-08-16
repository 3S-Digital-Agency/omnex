import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../app/AuthProvider';
import { Button } from '../../components/ui/Button';
import { Field } from '../../components/ui/Field';
import { Input } from '../../components/ui/Input';
import { brand } from '../../lib/brand';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { AuthLayout } from './AuthLayout';
import { SocialLoginButtons } from './SocialLoginButtons';

export function LoginPage() {
  const { login } = useAuth();
  const { t } = useI18n();
  const navigate = useNavigate();
  const [email, setEmail] = useState('demo@omnex.dev');
  const [password, setPassword] = useState('password');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setLoading(true);
    try {
      const result = await login(email, password);
      navigate(result === 'mfa' ? '/mfa' : '/');
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthLayout title={t('auth.signIn')} subtitle={t('auth.welcomeBack', { name: brand.name })}>
      <form onSubmit={onSubmit} className="space-y-4">
        <Field label={t('auth.email')} htmlFor="email">
          <Input
            id="email"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
            autoComplete="email"
          />
        </Field>
        <Field label={t('auth.password')} htmlFor="password">
          <Input
            id="password"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            autoComplete="current-password"
          />
        </Field>
        {error ? (
          <div className="rounded-md border border-red-800 bg-red-950 px-3 py-2 text-sm text-red-200">{error}</div>
        ) : null}
        <Button type="submit" className="w-full" loading={loading}>
          {t('auth.signIn')}
        </Button>
      </form>
      <p className="mt-4 text-sm text-zinc-500">
        {t('auth.noAccount')}{' '}
        <Link to="/register" className="text-brand-400 hover:underline">
          {t('auth.createOne')}
        </Link>
      </p>
      <p className="mt-2 text-xs text-zinc-600">{t('auth.demoCredentials', { email: 'demo@omnex.dev', password: 'password' })}</p>
      <SocialLoginButtons />
    </AuthLayout>
  );
}
