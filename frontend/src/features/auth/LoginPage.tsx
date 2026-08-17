import { AuthLayout } from './AuthLayout';
import { CrossDeviceLogin } from './CrossDeviceLogin';
import { SocialLoginButtons } from './SocialLoginButtons';

export function LoginPage() {
  return (
    <AuthLayout brandCard>
      <CrossDeviceLogin />
      <div className="my-3 flex items-center gap-3">
        <div className="h-px flex-1 bg-edge" />
        <span className="text-xs text-zinc-500">ou</span>
        <div className="h-px flex-1 bg-edge" />
      </div>
      <SocialLoginButtons standalone />
    </AuthLayout>
  );
}
