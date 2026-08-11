"use client";

import { FormEvent, useState, useSyncExternalStore } from "react";
import { DashboardHeader } from "@/components/DashboardHeader";
import { PowerBIViewer } from "@/components/PowerBIViewer";

const SESSION_KEY = "ecoleta_dashboard_authenticated";
const SESSION_EVENT = "ecoleta-dashboard-auth-change";
const DASHBOARD_USER = process.env.NEXT_PUBLIC_DASHBOARD_USER || "admin";
const DASHBOARD_PASSWORD = process.env.NEXT_PUBLIC_DASHBOARD_PASSWORD ?? "";

export function DashboardGate() {
  const authenticated = useSyncExternalStore(
    (callback) => {
      window.addEventListener(SESSION_EVENT, callback);
      return () => window.removeEventListener(SESSION_EVENT, callback);
    },
    () => sessionStorage.getItem(SESSION_KEY) === "true",
    () => false,
  );
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!DASHBOARD_PASSWORD) {
      setError("Dashboard sem senha configurada no build (NEXT_PUBLIC_DASHBOARD_PASSWORD).");
      return;
    }
    if (username.trim() !== DASHBOARD_USER || password !== DASHBOARD_PASSWORD) {
      setError("Usuário ou senha incorretos.");
      return;
    }
    sessionStorage.setItem(SESSION_KEY, "true");
    window.dispatchEvent(new Event(SESSION_EVENT));
    setError("");
  };

  const handleLogout = () => {
    sessionStorage.removeItem(SESSION_KEY);
    window.dispatchEvent(new Event(SESSION_EVENT));
  };

  if (!authenticated) {
    return (
      <main className="min-h-screen bg-[var(--color-bg-dark)] flex items-center justify-center p-6 text-white">
        <form onSubmit={handleSubmit} className="w-full max-w-md rounded-2xl border border-[var(--color-border-dark)] bg-[rgba(255,255,255,0.04)] p-8 shadow-2xl">
          <p className="eyebrow mb-3">Área restrita</p>
          <h1 className="text-2xl font-bold">Dashboard Ecoleta</h1>
          <p className="mt-2 text-sm text-[var(--color-text-on-dark)]">Informe suas credenciais para acessar o painel de gestão.</p>
          <div className="mt-7 space-y-4">
            <label className="block text-sm font-medium" htmlFor="dashboard-user">Usuário
              <input id="dashboard-user" autoComplete="username" value={username} onChange={(event) => setUsername(event.target.value)} className="mt-2 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/20 px-4 py-3 text-white outline-none focus:border-[var(--color-accent)]" required />
            </label>
            <label className="block text-sm font-medium" htmlFor="dashboard-password">Senha
              <input id="dashboard-password" type="password" autoComplete="current-password" value={password} onChange={(event) => setPassword(event.target.value)} className="mt-2 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/20 px-4 py-3 text-white outline-none focus:border-[var(--color-accent)]" required />
            </label>
          </div>
          {error && <p role="alert" className="mt-4 text-sm text-red-300">{error}</p>}
          <button type="submit" className="mt-6 w-full rounded-full bg-[var(--color-accent)] px-5 py-3 text-sm font-semibold text-[var(--color-bg-dark)] transition-opacity hover:opacity-90">Entrar no dashboard</button>
        </form>
      </main>
    );
  }

  return (
    <div className="flex h-dvh flex-col overflow-hidden bg-[var(--color-bg-dark)] font-sans text-white" onContextMenuCapture={(event) => event.preventDefault()}>
      <DashboardHeader onLogout={handleLogout} />
      <main className="min-h-0 flex-1 pt-16"><PowerBIViewer /></main>
    </div>
  );
}
