const TOKEN_KEY = 'nexus.token';
const ORG_KEY = 'nexus.organization';

export const session = {
  getToken(): string | null {
    return localStorage.getItem(TOKEN_KEY);
  },

  setToken(token: string): void {
    localStorage.setItem(TOKEN_KEY, token);
  },

  getOrganizationId(): string | null {
    return localStorage.getItem(ORG_KEY);
  },

  setOrganizationId(id: string | null): void {
    if (id === null) {
      localStorage.removeItem(ORG_KEY);
    } else {
      localStorage.setItem(ORG_KEY, id);
    }
  },

  clear(): void {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(ORG_KEY);
  },
};
