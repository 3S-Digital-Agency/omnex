import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../app/AuthProvider';
import { Button } from '../../components/ui/Button';
import { Field } from '../../components/ui/Field';
import { Input } from '../../components/ui/Input';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { AuthLayout } from './AuthLayout';
import { SocialLoginButtons } from './SocialLoginButtons';

export function RegisterPage() {
  const { register } = useAuth();
  const { t } = useI18n();
  const navigate = useNavigate();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setLoading(true);
    try {
      await register(name, email, password);
      navigate('/organizations');
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthLayout title={t('auth.createAccount')} subtitle={t('auth.registerSubtitle')}>
      <form onSubmit={onSubmit} className="space-y-4">
        <Field label={t('auth.fullName')} htmlFor="name">
          <Input id="name" value={name} onChange={(e) => setName(e.target.value)} required autoComplete="name" />
        </Field>
        <Field label={t('auth.email')} htmlFor="email">
          <Input id="email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required autoComplete="email" />
        </Field>
        <Field label={t('auth.password')} htmlFor="password" hint={t('auth.atLeast8')}>
          <Input id="password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} required minLength={8} autoComplete="new-password" />
        </Field>
        {error ? (
          <div className="rounded-md border border-red-800 bg-red-950 px-3 py-2 text-sm text-red-200">{error}</div>
        ) : null}
        <Button type="submit" className="w-full" loading={loading}>
          {t('auth.createAccountBtn')}
        </Button>
      </form>
      <p className="mt-4 text-sm text-zinc-500">
        {t('auth.alreadyHave')}{' '}
        <Link to="/login" className="text-brand-400 hover:underline">
          {t('auth.signIn')}
        </Link>
      </p>
      <SocialLoginButtons />
    </AuthLayout>
  );
}
