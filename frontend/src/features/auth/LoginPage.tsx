import { AuthLayout } from './AuthLayout';
import { SocialLoginButtons } from './SocialLoginButtons';

export function LoginPage() {
  return (
    <AuthLayout brandCard>
      <SocialLoginButtons standalone />
    </AuthLayout>
  );
}
