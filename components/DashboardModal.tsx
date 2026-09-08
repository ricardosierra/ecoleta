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
 * Caixa de diálogo do dashboard: fundo escurecido, Esc fecha, clique fora
 * fecha, foco vai para o painel ao abrir. Todo modal das telas administrativas
 * passa por aqui para ter a mesma aparência e o mesmo comportamento.
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
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") onCloseRef.current();
    };
    document.addEventListener("keydown", onKey);
    panelRef.current?.focus();

    return () => document.removeEventListener("keydown", onKey);
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
