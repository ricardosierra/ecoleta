"use client";

import { useEffect, useId, useRef, type ReactNode } from "react";
import { CloseIcon } from "@/components/icons";

type DashboardModalProps = {
  title: string;
  /** Ícone desenhado no chip ao lado do título. */
  icon?: ReactNode;
  /** Cor do chip do ícone. `danger` para exclusões. */
  tone?: "default" | "info" | "warning" | "danger" | "success";
  size?: "sm" | "md" | "lg";
  onClose: () => void;
  /** Um formulário como filho: o `<form>` vira o próprio painel. */
  as?: "div" | "form";
  onSubmit?: (event: React.FormEvent<HTMLFormElement>) => void;
  children: ReactNode;
};

const toneClass: Record<NonNullable<DashboardModalProps["tone"]>, string> = {
  default: "bg-white/10 text-white",
  info: "bg-sky-500/15 text-sky-300",
  warning: "bg-amber-500/15 text-amber-300",
  danger: "bg-red-500/15 text-red-300",
  success: "bg-[var(--color-accent-soft)] text-[var(--color-accent)]",
};

const sizeClass: Record<NonNullable<DashboardModalProps["size"]>, string> = {
  sm: "max-w-md",
  md: "max-w-lg",
  lg: "max-w-3xl",
};

/**
 * Seletor do que recebe foco por Tab dentro do painel. `[tabindex="-1"]` fica
 * de fora de propósito: é o próprio painel, que só recebe foco por código.
 */
const FOCAVEIS =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Caixa de diálogo do dashboard: fundo escurecido, Esc fecha, clique fora
 * fecha, foco vai para o painel ao abrir. Todo modal das telas administrativas
 * passa por aqui para ter a mesma aparência e o mesmo comportamento.
 *
 * `aria-modal="true"` promete que o resto da página está fora de alcance. Para
 * o leitor de tela isso basta, mas o Tab do teclado não obedece ao atributo:
 * sem prender o ciclo aqui, quem navega por teclado sai do diálogo para a
 * página atrás dele e continua "dentro" de um modal que não vê mais. Por isso
 * o Tab dá a volta no painel, e ao fechar o foco volta para quem abriu.
 */
export function DashboardModal({
  title,
  icon,
  tone = "default",
  size = "md",
  onClose,
  as = "div",
  onSubmit,
  children,
}: DashboardModalProps) {
  const titleId = useId();
  const panelRef = useRef<HTMLElement | null>(null);

  // `onClose` costuma ser uma arrow nova a cada render; guardar em ref evita
  // religar o listener (e roubar o foco de quem digita) a cada tecla.
  const onCloseRef = useRef(onClose);
  useEffect(() => {
    onCloseRef.current = onClose;
  });

  useEffect(() => {
    const anterior = document.activeElement instanceof HTMLElement ? document.activeElement : null;

    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        onCloseRef.current();
        return;
      }
      if (event.key !== "Tab") return;

      const painel = panelRef.current;
      if (!painel) return;

      const alvos = Array.from(painel.querySelectorAll<HTMLElement>(FOCAVEIS));
      if (alvos.length === 0) {
        event.preventDefault();
        painel.focus();
        return;
      }

      const primeiro = alvos[0];
      const ultimo = alvos[alvos.length - 1];
      const atual = document.activeElement;

      // Vindo do próprio painel, Shift+Tab volta para o fim em vez de sair.
      if (event.shiftKey && (atual === primeiro || atual === painel)) {
        event.preventDefault();
        ultimo.focus();
      } else if (!event.shiftKey && atual === ultimo) {
        event.preventDefault();
        primeiro.focus();
      }
    };

    document.addEventListener("keydown", onKey);
    panelRef.current?.focus();

    return () => {
      document.removeEventListener("keydown", onKey);
      // Quem abriu o diálogo recebe o foco de volta; se saiu do DOM enquanto
      // o modal estava aberto, focus() não faz nada e o navegador decide.
      anterior?.focus();
    };
  }, []);

  // O painel recebe foco ao abrir só para o Esc e o teclado terem um ponto de
  // partida. O anel de foco não aparece nele por causa da regra
  // `[tabindex="-1"]:focus-visible` em globals.css — ver o comentário de lá.
  const panelClass = `w-full ${sizeClass[size]} max-h-[calc(100dvh-2rem)] overflow-y-auto rounded-3xl border border-[var(--color-border-dark)] bg-[var(--color-bg-dark)] p-5 text-white shadow-2xl sm:p-6`;

  const content = (
    <>
      <div className="mb-4 flex items-start justify-between gap-3">
        <div className="flex min-w-0 items-center gap-3">
          {icon && (
            <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${toneClass[tone]}`}>
              {icon}
            </span>
          )}
          <h3 id={titleId} className="truncate text-lg font-bold sm:text-xl">
            {title}
          </h3>
        </div>
        <button
          type="button"
          onClick={onClose}
          aria-label="Fechar"
          className="-mr-1 -mt-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-white/60 transition-colors hover:bg-white/10 hover:text-white"
        >
          <CloseIcon width={18} height={18} />
        </button>
      </div>
      {children}
    </>
  );

  const setPanel = (node: HTMLElement | null) => {
    panelRef.current = node;
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm print:hidden"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose();
      }}
    >
      {as === "form" ? (
        <form
          ref={setPanel}
          role="dialog"
          aria-modal="true"
          aria-labelledby={titleId}
          tabIndex={-1}
          className={panelClass}
          onSubmit={onSubmit}
        >
          {content}
        </form>
      ) : (
        <div ref={setPanel} role="dialog" aria-modal="true" aria-labelledby={titleId} tabIndex={-1} className={panelClass}>
          {content}
        </div>
      )}
    </div>
  );
}

/** Botões de rodapé dos modais, alinhados à direita e empilhados no celular. */
export function ModalActions({ children }: { children: ReactNode }) {
  return <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end sm:gap-3">{children}</div>;
}
