"use client";

import { FormEvent, useEffect, useState } from "react";
import { DashboardHeader } from "@/components/DashboardHeader";
import { PowerBIViewer } from "@/components/PowerBIViewer";

type User = {
  id: number;
  login: string;
  email: string | null;
  role: string;
  force_password_change: boolean;
};

export function DashboardGate({ children }: { children?: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  // Form states
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [error, setError] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    fetch("/api/auth/me.php")
      .then((res) => res.json())
      .then((data) => {
        if (data.ok && data.user) {
          setUser(data.user);
        }
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  const handleLogin = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError("");
    setIsSubmitting(true);

    try {
      const res = await fetch("/api/auth/login.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ username, password }),
      });
      const data = await res.json();
      
      if (res.ok && data.ok && data.user) {
        setUser(data.user);
      } else {
        setError(data.error || "Erro ao fazer login.");
      }
    } catch (e) {
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
      const res = await fetch("/api/auth/change_password.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ new_password: newPassword }),
      });
      const data = await res.json();
      
      if (res.ok && data.ok) {
        setUser((prev) => prev ? { ...prev, force_password_change: false } : null);
        setNewPassword("");
      } else {
        setError(data.error || "Erro ao trocar senha.");
      }
    } catch (e) {
      setError("Erro de conexão.");
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleLogout = async () => {
    await fetch("/api/auth/logout.php");
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
            <label className="block text-sm font-medium" htmlFor="dashboard-user">Usuário
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
    <div className="flex h-dvh flex-col overflow-hidden bg-[var(--color-bg-dark)] font-sans text-white">
      <DashboardHeader onLogout={handleLogout} user={user} />
      <main className="min-h-0 flex-1 pt-16">
        {children ? children : <PowerBIViewer />}
      </main>
    </div>
  );
}
