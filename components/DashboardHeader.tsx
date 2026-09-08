"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useState } from "react";
import Logo from "@/components/Logo";
import { CloseIcon, LogOutIcon, MenuIcon } from "@/components/icons";
import { dashboardNavLinks } from "@/lib/authz";

type User = {
  id: number;
  login: string;
  role: string;
};

type DashboardHeaderProps = {
  onLogout: () => void;
  user?: User;
};

/** Ativo quando a rota atual é a do link ou está dentro dela (o Painel só na raiz). */
function isActiveLink(pathname: string, href: string): boolean {
  if (href === "/dashboard") {
    return pathname === "/dashboard" || pathname === "/dashboard/";
  }

  return pathname === href || pathname.startsWith(`${href}/`);
}

export function DashboardHeader({ onLogout, user }: DashboardHeaderProps) {
  const navLinks = dashboardNavLinks(user);
  const pathname = usePathname() ?? "";
  const [menuOpen, setMenuOpen] = useState(false);

  const linkClass = (href: string) =>
    `whitespace-nowrap rounded-full px-3 py-1.5 text-sm font-medium transition-colors ${
      isActiveLink(pathname, href)
        ? "bg-[var(--color-accent-soft)] text-[var(--color-accent)]"
        : "text-white/80 hover:bg-white/5 hover:text-white"
    }`;

  return (
    <header className="relative z-40 shrink-0 border-b border-[var(--color-border-dark)] bg-[var(--color-bg-dark)]/95 backdrop-blur-md print:hidden">
      <div className="flex h-16 items-center justify-between gap-3 px-4 sm:px-6">
        <div className="flex min-w-0 items-center gap-3 sm:gap-4">
          <Link href="/" title="Ir para o site da Ecoleta" className="flex shrink-0 items-center">
            <Logo variant="white" height={30} />
          </Link>
          <span className="hidden h-4 w-px bg-[var(--color-border-dark)] xl:inline-block" />
          <span className="hidden rounded-full border border-[var(--color-accent)]/20 bg-[var(--color-accent-soft)] px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wider text-[var(--color-accent)] xl:inline-block">
            Painel BI
          </span>
          <span className="hidden h-4 w-px bg-[var(--color-border-dark)] xl:inline-block" />
          {/* Navegação de tela grande. No celular vira o menu abaixo. */}
          <nav aria-label="Navegação do dashboard" className="hidden items-center gap-1 lg:flex">
            {navLinks.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                aria-current={isActiveLink(pathname, link.href) ? "page" : undefined}
                className={linkClass(link.href)}
              >
                {link.label}
              </Link>
            ))}
          </nav>
        </div>

        <div className="flex shrink-0 items-center gap-1 sm:gap-2">
          {user && (
            <span className="hidden max-w-[10rem] truncate text-sm text-white/70 xl:block">
              Olá, <strong className="font-semibold text-white">{user.login}</strong>
            </span>
          )}
          <button
            type="button"
            onClick={onLogout}
            className="hidden items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium text-[var(--color-text-on-dark)] transition-colors hover:bg-white/5 hover:text-white lg:inline-flex"
          >
            <LogOutIcon width={15} height={15} />
            Sair
          </button>
          <button
            type="button"
            onClick={() => setMenuOpen((open) => !open)}
            aria-expanded={menuOpen}
            aria-controls="dashboard-mobile-menu"
            aria-label={menuOpen ? "Fechar menu" : "Abrir menu"}
            className="inline-flex h-10 w-10 items-center justify-center rounded-full text-white transition-colors hover:bg-white/10 lg:hidden"
          >
            {menuOpen ? <CloseIcon width={22} height={22} /> : <MenuIcon width={22} height={22} />}
          </button>
        </div>
      </div>

      {menuOpen && (
        <nav
          id="dashboard-mobile-menu"
          aria-label="Navegação do dashboard"
          className="absolute inset-x-0 top-full border-b border-[var(--color-border-dark)] bg-[var(--color-bg-dark)] px-4 pb-4 pt-2 shadow-2xl lg:hidden"
        >
          <ul className="space-y-1">
            {navLinks.map((link) => {
              const active = isActiveLink(pathname, link.href);
              return (
                <li key={link.href}>
                  <Link
                    href={link.href}
                    onClick={() => setMenuOpen(false)}
                    aria-current={active ? "page" : undefined}
                    className={`block rounded-xl px-4 py-3 text-sm font-medium transition-colors ${
                      active
                        ? "bg-[var(--color-accent)] text-[var(--color-bg-dark)]"
                        : "text-white/85 hover:bg-white/5 hover:text-white"
                    }`}
                  >
                    {link.label}
                  </Link>
                </li>
              );
            })}
          </ul>
          <div className="mt-3 flex items-center justify-between gap-3 border-t border-[var(--color-border-dark)] pt-3">
            {user ? (
              <span className="truncate text-sm text-white/70">
                Olá, <strong className="font-semibold text-white">{user.login}</strong>
              </span>
            ) : (
              <span />
            )}
            <button
              type="button"
              onClick={onLogout}
              className="inline-flex items-center gap-1.5 rounded-full border border-white/15 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-white/10"
            >
              <LogOutIcon width={15} height={15} />
              Sair
            </button>
          </div>
        </nav>
      )}
    </header>
  );
}
