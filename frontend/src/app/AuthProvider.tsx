import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { api } from '../lib/api';
import { session } from '../lib/api/session';
import type {
  AuthSession,
  InvitationDto,
  MembershipDto,
  OrganizationDto,
  UserDto,
} from '../lib/api/types';

type Status = 'loading' | 'unauthenticated' | 'authenticated' | 'mfa';

interface AuthState {
  status: Status;
  user: UserDto | null;
  memberships: MembershipDto[];
  activeOrganization: OrganizationDto | null;
  permissions: string[];
  pendingInvitations: InvitationDto[];
}

interface AuthContextValue extends AuthState {
  login: (email: string, password: string) => Promise<'authenticated' | 'mfa'>;
  register: (name: string, email: string, password: string) => Promise<void>;
  verifyMfa: (code: string, recoveryCode?: string) => Promise<void>;
  completeSocial: (code: string) => Promise<void>;
  logout: () => Promise<void>;
  switchOrganization: (orgId: string) => Promise<void>;
  acceptInvitation: (token: string) => Promise<void>;
  refresh: () => Promise<void>;
  hasPermission: (permission: string) => boolean;
}

const emptyState: AuthState = {
  status: 'loading',
  user: null,
  memberships: [],
  activeOrganization: null,
  permissions: [],
  pendingInvitations: [],
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<AuthState>(emptyState);
  const [mfaToken, setMfaToken] = useState<string | null>(null);

  const applySession = useCallback((auth: AuthSession) => {
    session.setToken(auth.token);
    session.setOrganizationId(auth.active_organization?.id ?? null);
    setState({
      status: 'authenticated',
      user: auth.user,
      memberships: auth.memberships,
      activeOrganization: auth.active_organization,
      permissions: auth.permissions,
      pendingInvitations: auth.pending_invitations,
    });
  }, []);

  const refresh = useCallback(async () => {
    const me = await api.me();
    setState((s) => ({
      ...s,
      status: 'authenticated',
      user: me.user,
      memberships: me.memberships,
      activeOrganization: me.active_organization,
      permissions: me.permissions,
      pendingInvitations: me.pending_invitations,
    }));
  }, []);

  useEffect(() => {
    if (!session.getToken()) {
      setState((s) => ({ ...s, status: 'unauthenticated' }));
      return;
    }
    refresh().catch(() => {
      session.clear();
      setState((s) => ({ ...s, status: 'unauthenticated' }));
    });
  }, [refresh]);

  const login = useCallback(
    async (email: string, password: string): Promise<'authenticated' | 'mfa'> => {
      const res = await api.login({ email, password });
      if ('mfa_required' in res) {
        setMfaToken(res.mfa_token);
        setState((s) => ({ ...s, status: 'mfa' }));
        return 'mfa';
      }
      applySession(res);
      return 'authenticated';
    },
    [applySession],
  );

  const register = useCallback(
    async (name: string, email: string, password: string) => {
      const res = await api.register({ name, email, password, password_confirmation: password });
      applySession(res);
    },
    [applySession],
  );

  const verifyMfa = useCallback(
    async (code: string, recoveryCode?: string) => {
      if (!mfaToken) throw new Error('No MFA challenge in progress.');
      const res = await api.verifyMfa({ mfa_token: mfaToken, code, recovery_code: recoveryCode });
      setMfaToken(null);
      applySession(res);
    },
    [mfaToken, applySession],
  );

  const completeSocial = useCallback(
    async (code: string) => {
      const res = await api.completeSocial(code);
      applySession(res);
    },
    [applySession],
  );

  const logout = useCallback(async () => {
    await api.logout().catch(() => undefined);
    session.clear();
    setMfaToken(null);
    setState({ ...emptyState, status: 'unauthenticated' });
  }, []);

  const switchOrganization = useCallback(
    async (orgId: string) => {
      await api.switchOrganization(orgId);
      await refresh();
    },
    [refresh],
  );

  const acceptInvitation = useCallback(
    async (token: string) => {
      await api.acceptInvitation(token);
      await refresh();
    },
    [refresh],
  );

  const hasPermission = useCallback(
    (permission: string) => state.permissions.includes(permission),
    [state.permissions],
  );

  const value = useMemo<AuthContextValue>(
    () => ({
      ...state,
      login,
      register,
      verifyMfa,
      completeSocial,
      logout,
      switchOrganization,
      acceptInvitation,
      refresh,
      hasPermission,
    }),
    [state, login, register, verifyMfa, completeSocial, logout, switchOrganization, acceptInvitation, refresh, hasPermission],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
