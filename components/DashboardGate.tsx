"use client";

import { createContext, FormEvent, useContext, useEffect, useState } from "react";
import { DashboardHeader } from "@/components/DashboardHeader";
import { PowerBIViewer } from "@/components/PowerBIViewer";
import { apiFetch, apiPostJson, clearCsrfToken, setCsrfToken } from "@/lib/dashboard-api";

export type DashboardUser = {
  id: number;
  login: string;
  email: string | null;
  role: string;
  group_id?: number | null;
  group_name?: string | null;
  group_powerbi_url?: string | null;
  force_password_change: boolean;
};

type DashboardAuthContextType = {
  user: DashboardUser | null;
  logout: () => Promise<void>;
  refreshUser: () => Promise<void>;
};

const DashboardAuthContext = createContext<DashboardAuthContextType>({
  user: null,
  logout: async () => {},
  refreshUser: async () => {},
});

export function useDashboardAuth() {
  return useContext(DashboardAuthContext);
}

export function DashboardGate({ children }: { children?: React.ReactNode }) {
  const [user, setUser] = useState<DashboardUser | null>(null);
  const [loading, setLoading] = useState(true);

  // Form states
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [error, setError] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);

  const refreshUser = async () => {
    try {
      const res = await fetch("/api/auth/me.php");
      const data = await res.json();
      // me.php emite o token CSRF da sessão inclusive antes do login.
      setCsrfToken(data?.csrf_token);
      if (res.ok && data.ok && data.user) {
        setUser(data.user);
      } else {
        setUser(null);
      }
    } catch {
      setUser(null);
    }
  };

  useEffect(() => {
    let isMounted = true;
    fetch("/api/auth/me.php")
      .then((res) => res.json())
      .then((data) => {
        setCsrfToken(data?.csrf_token);
        if (isMounted) {
          if (data.ok && data.user) {
            setUser(data.user);
          } else {
            setUser(null);
          }
        }
      })
      .catch(() => {
        if (isMounted) setUser(null);
      })
      .finally(() => {
        if (isMounted) setLoading(false);
      });

    return () => {
      isMounted = false;
    };
  }, []);

  const handleLogin = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError("");
    setIsSubmitting(true);

    try {
      const res = await apiPostJson("/api/auth/login.php", { username, password });
      const data = await res.json();

      if (res.ok && data.ok && data.user) {
        // O login regenera a sessão e emite um token novo.
        setCsrfToken(data.csrf_token);
        setUser(data.user);
      } else {
        setError(data.error || "Erro ao fazer login.");
      }
    } catch {
      setError("Erro de conexão.");
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleChangePassword = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError("");
    if (newPassword.length < 6) {
      setError("A senha deve ter pelo menos 6 caracteres.");
      return;
    }

    setIsSubmitting(true);
    try {
      const res = await apiPostJson("/api/auth/change_password.php", { new_password: newPassword });
      const data = await res.json();

      if (res.ok && data.ok) {
        // Trocar a senha também regenera a sessão.
        setCsrfToken(data.csrf_token);
        setUser((prev) => prev ? { ...prev, force_password_change: false } : null);
        setNewPassword("");
      } else {
        setError(data.error || "Erro ao trocar senha.");
      }
    } catch {
      setError("Erro de conexão.");
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleLogout = async () => {
    try {
      await apiFetch("/api/auth/logout.php", { method: "POST" });
    } catch {
      // Mesmo sem resposta do servidor, a sessão local é descartada.
    }
    clearCsrfToken();
    setUser(null);
    window.location.href = "/dashboard";
  };

  if (loading) {
    return <main className="min-h-screen bg-[var(--color-bg-dark)] flex items-center justify-center p-6 text-white">Carregando...</main>;
  }

  if (!user) {
    return (
      <main className="min-h-screen bg-[var(--color-bg-dark)] flex items-center justify-center p-6 text-white">
        <form onSubmit={handleLogin} className="w-full max-w-md rounded-2xl border border-[var(--color-border-dark)] bg-[rgba(255,255,255,0.04)] p-8 shadow-2xl">
          <p className="eyebrow mb-3">Área restrita</p>
          <h1 className="text-2xl font-bold">Dashboard Ecoleta</h1>
          <p className="mt-2 text-sm text-[var(--color-text-on-dark)]">Informe suas credenciais para acessar o painel de gestão.</p>
          <div className="mt-7 space-y-4">
            <label className="block text-sm font-medium" htmlFor="dashboard-user">Usuário ou E-mail
              <input id="dashboard-user" autoComplete="username" value={username} onChange={(e) => setUsername(e.target.value)} className="mt-2 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/20 px-4 py-3 text-white outline-none focus:border-[var(--color-accent)]" required />
            </label>
            <label className="block text-sm font-medium" htmlFor="dashboard-password">Senha
              <input id="dashboard-password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} className="mt-2 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/20 px-4 py-3 text-white outline-none focus:border-[var(--color-accent)]" required />
            </label>
          </div>
          {error && <p role="alert" className="mt-4 text-sm text-red-300">{error}</p>}
          <button type="submit" disabled={isSubmitting} className="mt-6 w-full rounded-full bg-[var(--color-accent)] px-5 py-3 text-sm font-semibold text-[var(--color-bg-dark)] transition-opacity hover:opacity-90 disabled:opacity-50">Entrar no dashboard</button>
        </form>
      </main>
    );
  }

  if (user.force_password_change) {
    return (
      <main className="min-h-screen bg-[var(--color-bg-dark)] flex items-center justify-center p-6 text-white">
        <form onSubmit={handleChangePassword} className="w-full max-w-md rounded-2xl border border-[var(--color-border-dark)] bg-[rgba(255,255,255,0.04)] p-8 shadow-2xl">
          <h1 className="text-2xl font-bold">Definir Nova Senha</h1>
          <p className="mt-2 text-sm text-[var(--color-text-on-dark)]">Por segurança, é obrigatório cadastrar uma nova senha no seu primeiro acesso.</p>
          <div className="mt-7 space-y-4">
            <label className="block text-sm font-medium" htmlFor="new-password">Nova Senha
              <input id="new-password" type="password" minLength={6} value={newPassword} onChange={(e) => setNewPassword(e.target.value)} className="mt-2 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/20 px-4 py-3 text-white outline-none focus:border-[var(--color-accent)]" required />
            </label>
          </div>
          {error && <p role="alert" className="mt-4 text-sm text-red-300">{error}</p>}
          <button type="submit" disabled={isSubmitting} className="mt-6 w-full rounded-full bg-[var(--color-accent)] px-5 py-3 text-sm font-semibold text-[var(--color-bg-dark)] transition-opacity hover:opacity-90 disabled:opacity-50">Salvar nova senha</button>
        </form>
      </main>
    );
  }

  return (
    <DashboardAuthContext.Provider value={{ user, logout: handleLogout, refreshUser }}>
      <div className="flex h-dvh flex-col overflow-hidden bg-[var(--color-bg-dark)] font-sans text-white">
        <DashboardHeader onLogout={handleLogout} user={user} />
        <main className="min-h-0 flex-1 pt-16">
          {children ? children : <PowerBIViewer />}
        </main>
      </div>
    </DashboardAuthContext.Provider>
  );
}
