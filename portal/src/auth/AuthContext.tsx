import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";
import { useVisibleRefresh } from "../hooks/useVisibleRefresh";
import { api } from "../api/client";
import { PortalApiError } from "../api/errors";
import type { PortalPrivileges, PortalUser } from "../api/types";

type AuthState = {
  loading: boolean;
  authenticated: boolean;
  user: PortalUser | null;
  privileges: PortalPrivileges | null;
  login: (account: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
};

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [loading, setLoading] = useState(true);
  const [authenticated, setAuthenticated] = useState(false);
  const [user, setUser] = useState<PortalUser | null>(null);
  const [privileges, setPrivileges] = useState<PortalPrivileges | null>(null);

  const refresh = useCallback(async () => {
    try {
      const s = await api.getSession();
      setAuthenticated(!!s.authenticated);
      setUser(s.user ?? null);
      setPrivileges(s.privileges ?? null);
    } catch (err) {
      if (err instanceof PortalApiError && err.isNetwork) {
        // Keep prior session UI; offline banner handles messaging.
        return;
      }
      setAuthenticated(false);
      setUser(null);
      setPrivileges(null);
    }
  }, []);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        await refresh();
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [refresh]);

  useVisibleRefresh(refresh);

  const login = useCallback(async (account: string, password: string) => {
    const s = await api.login(account, password);
    if (!s.authenticated && !s.user) {
      throw new PortalApiError("AUTH_REQUIRED", "Anmeldung fehlgeschlagen.", 401);
    }
    setAuthenticated(true);
    setUser(s.user ?? null);
    setPrivileges(s.privileges ?? null);
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.logout();
    } finally {
      setAuthenticated(false);
      setUser(null);
      setPrivileges(null);
    }
  }, []);

  const value = useMemo(
    () => ({ loading, authenticated, user, privileges, login, logout, refresh }),
    [loading, authenticated, user, privileges, login, logout, refresh]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth outside AuthProvider");
  return ctx;
}
