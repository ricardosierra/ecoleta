import Link from "next/link";
import Logo from "@/components/Logo";

export function DashboardHeader() {
  return (
    <header className="sticky top-0 z-50 bg-[#08150A]/90 backdrop-blur-md border-b border-[var(--color-border-dark)] px-4 sm:px-8 py-3.5 flex items-center justify-between">
      <div className="flex items-center gap-4">
        <Link href="/" title="Ir para o site da Ecoleta" className="flex items-center">
          <Logo variant="white" height={32} />
        </Link>
        <span className="hidden sm:inline-block h-4 w-px bg-[var(--color-border-dark)]" />
        <span className="hidden sm:inline-block text-xs font-semibold uppercase tracking-wider text-[var(--color-accent)] bg-[var(--color-accent-soft)] px-2.5 py-1 rounded-full border border-[var(--color-accent)]/20">
          Painel BI
        </span>
      </div>

        <div className="flex items-center gap-3">
        <Link
          href="/"
          className="text-xs font-medium text-[var(--color-text-on-dark)] hover:text-white px-3 py-1.5 rounded-full hover:bg-[rgba(255,255,255,0.06)] transition-colors hidden md:inline-block"
        >
          Ver Site Principal
        </Link>

          <div className="flex items-center gap-2 pl-2 border-l border-[var(--color-border-dark)]">
            <span className="w-2 h-2 rounded-full bg-[var(--color-accent)] inline-block" />
            <span className="text-xs font-semibold text-white">Painel ao vivo</span>
          </div>
      </div>
    </header>
  );
}
