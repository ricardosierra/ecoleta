import Link from "next/link";
import Logo from "@/components/Logo";

type DashboardHeaderProps = {
  onLogout: () => void;
};

export function DashboardHeader({ onLogout }: DashboardHeaderProps) {
  return (
    <header className="fixed inset-x-0 top-0 z-50 flex h-16 items-center justify-between border-b border-[var(--color-border-dark)] bg-[var(--color-bg-dark)]/95 px-4 backdrop-blur-md sm:px-8">
      <div className="flex items-center gap-4">
        <Link href="/" title="Ir para o site da Ecoleta" className="flex items-center">
          <Logo variant="white" height={32} />
        </Link>
        <span className="hidden sm:inline-block h-4 w-px bg-[var(--color-border-dark)]" />
        <span className="hidden sm:inline-block text-xs font-semibold uppercase tracking-wider text-[var(--color-accent)] bg-[var(--color-accent-soft)] px-2.5 py-1 rounded-full border border-[var(--color-accent)]/20">
          Painel BI
        </span>
      </div>

      <div className="flex items-center gap-2 sm:gap-3">
        <button
          type="button"
          onClick={onLogout}
          className="text-xs font-medium text-[var(--color-text-on-dark)] hover:text-white px-3 py-1.5 rounded-full hover:bg-[rgba(255,255,255,0.06)] transition-colors"
        >
          Sair
        </button>

        <div className="hidden items-center gap-2 border-l border-[var(--color-border-dark)] pl-2 sm:flex">
          <span className="inline-block h-2 w-2 rounded-full bg-[var(--color-accent)]" />
          <span className="text-xs font-semibold text-white">Painel ao vivo</span>
        </div>
      </div>
    </header>
  );
}
