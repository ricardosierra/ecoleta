import Link from "next/link";

type DashboardAccessDeniedProps = {
  /** O que a pessoa tentou abrir — entra na frase "permissão para acessar {area}". */
  area: string;
};

/**
 * Tela de recusa das áreas administrativas do dashboard.
 *
 * Antes existia só em `/dashboard/grupos`; as telas de usuários renderizavam a
 * gestão para qualquer sessão autenticada e dependiam do 403 da API para não
 * mostrar dados. Agora as três usam este mesmo bloqueio.
 */
export function DashboardAccessDenied({ area }: DashboardAccessDeniedProps) {
  return (
    <div className="p-8 text-center text-white">
      <div className="max-w-md mx-auto p-8 rounded-3xl bg-[rgba(255,255,255,0.03)] border border-red-500/30">
        <p className="text-3xl mb-3" aria-hidden="true">🔒</p>
        <h2 className="text-xl font-bold text-white mb-2">Acesso Restrito</h2>
        <p className="text-sm text-[var(--color-text-on-dark)] mb-6">
          Apenas usuários com perfil <strong>Root</strong> ou <strong>Master</strong> têm permissão
          para acessar {area}.
        </p>
        <Link
          href="/dashboard"
          className="inline-block bg-[var(--color-accent)] text-black px-6 py-2.5 rounded-full text-sm font-semibold hover:opacity-90"
        >
          Voltar ao Dashboard
        </Link>
      </div>
    </div>
  );
}
