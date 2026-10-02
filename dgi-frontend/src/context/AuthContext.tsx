import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from "react";
import api from "../services/api";
import type { AppUser } from "../types";

interface AuthContextValue {
  user: AppUser | null;
  loading: boolean;
  login: (token: string, user: AppUser) => void;
  /** Recharge l'utilisateur connecté depuis l'API (ex. après une modification de profil confirmée). */
  refreshUser: () => Promise<void>;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AppUser | null>(null);
  // Tant qu'on n'a pas vérifié le jeton existant auprès de l'API, on est en chargement.
  const [loading, setLoading] = useState<boolean>(() => Boolean(localStorage.getItem("token")));

  useEffect(() => {
    if (!localStorage.getItem("token")) return;
    api
      .get<AppUser>("/me")
      .then((res) => setUser(res.data))
      .catch(() => localStorage.removeItem("token"))
      .finally(() => setLoading(false));
  }, []);

  // Déclenché par l'intercepteur axios quand l'API répond 401 (jeton expiré/révoqué).
  useEffect(() => {
    const onExpired = () => setUser(null);
    window.addEventListener("auth:expired", onExpired);
    return () => window.removeEventListener("auth:expired", onExpired);
  }, []);

  const login = useCallback((token: string, nextUser: AppUser) => {
    localStorage.setItem("token", token);
    setUser(nextUser);
  }, []);

  const refreshUser = useCallback(async () => {
    if (!localStorage.getItem("token")) return;
    try {
      const res = await api.get<AppUser>("/me");
      setUser(res.data);
    } catch {
      // 401 : l'intercepteur axios déconnecte déjà l'utilisateur
    }
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.post("/logout");
    } catch {
      // jeton déjà invalide : on déconnecte quand même côté navigateur
    }
    localStorage.removeItem("token");
    setUser(null);
  }, []);

  return (
    <AuthContext.Provider value={{ user, loading, login, refreshUser, logout }}>{children}</AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth doit être utilisé dans <AuthProvider>");
  return ctx;
}
