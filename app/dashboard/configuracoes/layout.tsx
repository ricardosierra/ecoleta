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

const links = [
  { href: "/dashboard/configuracoes/usuarios", label: "Usuários" },
  { href: "/dashboard/configuracoes/grupos", label: "Grupos" },
  { href: "/dashboard/configuracoes/indicadores", label: "Indicadores" },
  { href: "/dashboard/configuracoes/empresas", label: "Empresas Parceiras" },
];

/**
 * Navegação de Configurações. Em tela grande é a coluna da esquerda, grudada
 * no topo enquanto o conteúdo rola; no celular vira uma faixa horizontal de
 * pílulas com rolagem lateral, também grudada no topo.
 */
function ConfiguracoesSidebar({ children }: { children: React.ReactNode }) {
  const { user } = useDashboardAuth();
  const pathname = usePathname() ?? "";

  if (!isAdmin(user)) {
    return <DashboardAccessDenied area="Configurações" />;
  }

  return (
    <div className="flex min-h-full flex-col lg:flex-row">
      <aside className="sticky top-0 z-30 shrink-0 border-b border-[var(--color-border-dark)] bg-[var(--color-bg-dark)]/95 backdrop-blur-md lg:static lg:w-60 lg:border-b-0 lg:border-r lg:bg-black/20 lg:backdrop-blur-none xl:w-64">
        <div className="px-4 py-3 lg:sticky lg:top-0 lg:p-6">
          <h2 className="hidden text-lg font-bold text-white lg:mb-5 lg:block">Configurações</h2>
          <nav aria-label="Seções de configurações" className="-mx-1 flex gap-1.5 overflow-x-auto px-1 lg:mx-0 lg:flex-col lg:overflow-visible lg:px-0">
            {links.map((link) => {
              const isActive = pathname.startsWith(link.href);
              return (
                <Link
                  key={link.href}
                  href={link.href}
                  aria-current={isActive ? "page" : undefined}
                  className={`shrink-0 whitespace-nowrap rounded-full px-4 py-2 text-sm font-medium transition-colors lg:py-2.5 ${
                    isActive
                      ? "bg-[var(--color-accent)] font-semibold text-[var(--color-bg-dark)]"
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
      <section className="min-w-0 flex-1">{children}</section>
    </div>
  );
}
