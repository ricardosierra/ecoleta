"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { usePathname } from "next/navigation";
import Button from "@/components/Button";
import Logo from "@/components/Logo";
import { CloseIcon, MenuIcon } from "@/components/icons";
import { siteConfig } from "@/lib/site.config";
import { cn } from "@/lib/cn";

export default function Header() {
  const [scrolled, setScrolled] = useState(false);
  const [open, setOpen] = useState(false);
  const pathname = usePathname();

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 12);
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  useEffect(() => {
    // Fechar menu mobile quando o usuário navega entre páginas.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setOpen(false);
  }, [pathname]);

  useEffect(() => {
    if (open) {
      document.body.style.overflow = "hidden";
    } else {
      document.body.style.overflow = "";
    }
    return () => {
      document.body.style.overflow = "";
    };
  }, [open]);

  return (
    <header
      className={cn(
        "fixed inset-x-0 top-0 z-50 bg-(--color-bg-dark) border-b transition-[backdrop-filter,box-shadow,border-color] duration-300",
        scrolled || open
          ? "backdrop-blur-md border-(--color-border-dark) shadow-[0_2px_18px_rgba(0,0,0,0.25)]"
          : "border-transparent"
      )}
    >
      <div className="container-page flex items-center justify-between h-[72px] md:h-[88px]">
        <Link
          href="/"
          aria-label="Página inicial Ecoleta"
          className="flex items-center"
        >
          <Logo variant="white" />
        </Link>

        <nav className="hidden md:flex items-center gap-8" aria-label="Navegação principal">
          {siteConfig.nav.map((item) => {
            const active = pathname.startsWith(item.href);
            return (
              <Link
                key={item.href}
                href={item.href}
                className={cn(
                  "text-sm font-medium transition-colors",
                  active
                    ? "text-(--color-accent)"
                    : "text-white/80 hover:text-white"
                )}
              >
                {item.label}
              </Link>
            );
          })}
        </nav>

        <div className="hidden md:flex items-center">
          <Button href="/contato" size="sm" variant="primary">
            Diagnóstico gratuito
          </Button>
        </div>

        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          aria-label={open ? "Fechar menu" : "Abrir menu"}
          aria-expanded={open}
          className="md:hidden inline-flex items-center justify-center size-11 rounded-full border border-(--color-border-dark) text-white hover:bg-white/5 transition-colors"
        >
          {open ? <CloseIcon /> : <MenuIcon />}
        </button>
      </div>

      {/* Mobile menu */}
      <div
        className={cn(
          "md:hidden overflow-y-auto fixed inset-x-0 top-[72px] bottom-0 z-40 bg-(--color-bg-dark) px-5 pb-10 pt-6 transition-[transform,opacity] duration-300 border-t border-(--color-border-dark)",
          open
            ? "translate-y-0 opacity-100"
            : "-translate-y-4 opacity-0 pointer-events-none"
        )}
      >
        <nav className="flex flex-col gap-1" aria-label="Navegação mobile">
          {siteConfig.nav.map((item) => {
            const active = pathname.startsWith(item.href);
            return (
              <Link
                key={item.href}
                href={item.href}
                className={cn(
                  "py-4 text-lg font-medium border-b border-(--color-border-dark) transition-colors",
                  active
                    ? "text-(--color-accent)"
                    : "text-white hover:text-(--color-accent)"
                )}
              >
                {item.label}
              </Link>
            );
          })}
        </nav>
        <div className="mt-8">
          <Button href="/contato" variant="primary" className="w-full">
            Diagnóstico gratuito
          </Button>
        </div>
      </div>
    </header>
  );
}
