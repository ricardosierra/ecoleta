"use client";

import { DashboardGate, useDashboardAuth } from "@/components/DashboardGate";
import { DashboardAccessDenied } from "@/components/DashboardAccessDenied";
import { isAdmin } from "@/lib/authz";
import Link from "next/link";
import { usePathname } from "next/navigation";

export default function ConfiguracoesLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <DashboardGate>
      <ConfiguracoesSidebar>{children}</ConfiguracoesSidebar>
    </DashboardGate>
  );
}

function ConfiguracoesSidebar({ children }: { children: React.ReactNode }) {
  const { user } = useDashboardAuth();
  const pathname = usePathname();

  if (!isAdmin(user)) {
    return <DashboardAccessDenied area="Configurações" />;
  }

  const links = [
    { href: "/dashboard/configuracoes/usuarios", label: "Usuários" },
    { href: "/dashboard/configuracoes/grupos", label: "Grupos" },
    { href: "/dashboard/configuracoes/indicadores", label: "Indicadores" },
    { href: "/dashboard/configuracoes/empresas", label: "Empresas Parceiras" },
  ];

  return (
    <div className="flex h-full bg-[var(--color-bg-dark)]">
      <aside className="w-64 border-r border-[var(--color-border-dark)] bg-black/20 flex-shrink-0 flex flex-col h-full">
        <div className="p-6">
          <h2 className="text-lg font-bold text-white mb-6">Configurações</h2>
          <nav className="space-y-1.5">
            {links.map((link) => {
              const isActive = pathname.startsWith(link.href);
              return (
                <Link
                  key={link.href}
                  href={link.href}
                  className={`block px-4 py-2.5 rounded-full text-sm font-medium transition-colors ${
                    isActive
                      ? "bg-[var(--color-accent)] text-black font-semibold"
                      : "text-white/70 hover:bg-white/10 hover:text-white"
                  }`}
                >
                  {link.label}
                </Link>
              );
            })}
          </nav>
        </div>
      </aside>
      <main className="flex-1 min-w-0 overflow-y-auto">
        {children}
      </main>
    </div>
  );
}
