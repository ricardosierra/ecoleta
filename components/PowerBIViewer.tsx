"use client";

import { useState } from "react";

const POWER_BI_SRC_BASE = process.env.NEXT_PUBLIC_POWERBI_URL ?? "";

export function PowerBIViewer() {
  const [isLoading, setIsLoading] = useState(true);
  // Timestamp para invalidar cache do iframe. O componente só renderiza depois do
  // login no client, então não há HTML pré-renderizado para divergir.
  const [iframeSrc] = useState(() =>
    POWER_BI_SRC_BASE
      ? `${POWER_BI_SRC_BASE}${POWER_BI_SRC_BASE.includes("?") ? "&" : "?"}t=${Date.now()}`
      : "",
  );

  if (!POWER_BI_SRC_BASE) {
    return (
      <div className="flex h-full w-full items-center justify-center bg-[var(--color-bg-dark)] p-6 text-center">
        <p className="text-sm font-medium text-[var(--color-text-on-dark)]">
          Painel indisponível: defina <code>NEXT_PUBLIC_POWERBI_URL</code> no ambiente do build.
        </p>
      </div>
    );
  }

  return (
    <div className="relative h-full w-full bg-[var(--color-bg-dark)]">
      {isLoading && (
        <div className="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 bg-[var(--color-bg-dark)] p-6 text-white">
          <div className="w-10 h-10 border-3 border-[var(--color-accent-soft)] border-t-[var(--color-accent)] rounded-full animate-spin" />
          <p className="text-sm font-medium text-[var(--color-text-on-dark)]">
            Carregando o painel BI Ecoleta...
          </p>
        </div>
      )}

      <iframe
        title="Bi Ecoleta"
        src={iframeSrc}
        className="h-full w-full border-0"
        allowFullScreen
        onLoad={() => setIsLoading(false)}
      />
    </div>
  );
}
