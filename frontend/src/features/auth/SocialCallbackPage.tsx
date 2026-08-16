import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useAuth } from '../../app/AuthProvider';
import { Spinner } from '../../components/ui/Spinner';
import { useI18n } from '../../lib/i18n';
import { AuthLayout } from './AuthLayout';

export function SocialCallbackPage() {
  const { completeSocial } = useAuth();
  const { t } = useI18n();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [error, setError] = useState(false);
  const started = useRef(false);

  const code = searchParams.get('code');

  useEffect(() => {
    if (started.current) return;
    started.current = true;

    if (!code) {
      setError(true);
      return;
    }

    completeSocial(code)
      .then(() => navigate('/', { replace: true }))
      .catch(() => setError(true));
  }, [code, completeSocial, navigate]);

  return (
    <AuthLayout title={t('auth.signIn')} subtitle={t('social.completing')}>
      {error ? (
        <div className="space-y-3 text-center">
          <p className="text-sm text-red-300">{t('social.error')}</p>
          <Link to="/login" className="text-sm text-brand-400 hover:underline">
            {t('social.retry')}
          </Link>
        </div>
      ) : (
        <div className="flex justify-center py-4">
          <Spinner />
        </div>
      )}
    </AuthLayout>
  );
}
