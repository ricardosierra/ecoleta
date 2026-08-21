"use client";

import { useState } from "react";

const DEFAULT_POWER_BI_SRC = process.env.NEXT_PUBLIC_POWERBI_URL ?? "";

function extractIframeSrc(input: string): string {
  if (!input) return "";
  const match = input.match(/<iframe.*?src=["']([^"']+)["']/i);
  return match ? match[1] : input.trim();
}

type PowerBIViewerProps = {
  url?: string | null;
  groupName?: string | null;
};

export function PowerBIViewer({ url, groupName }: PowerBIViewerProps) {
  const [isLoading, setIsLoading] = useState(true);
  
  const rawUrl = url || DEFAULT_POWER_BI_SRC;
  const cleanUrl = extractIframeSrc(rawUrl);

  const iframeSrc = cleanUrl
    ? `${cleanUrl}${cleanUrl.includes("?") ? "&" : "?"}t=${cleanUrl}`
    : "";

  if (!cleanUrl) {
    return (
      <div className="flex h-full w-full flex-col items-center justify-center bg-[var(--color-bg-dark)] p-6 text-center text-white">
        <div className="max-w-md rounded-2xl border border-[var(--color-border-dark)] bg-[rgba(255,255,255,0.03)] p-8 shadow-xl">
          <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-white/5 text-2xl">
            📊
          </div>
          <h2 className="text-xl font-bold text-white mb-2">Painel BI Indisponível</h2>
          <p className="text-sm text-[var(--color-text-on-dark)]">
            {groupName 
              ? `O relatório do Power BI para o grupo "${groupName}" ainda não possui código ou link configurado.` 
              : "Nenhum relatório do Power BI está configurado para o seu usuário ou grupo."}
          </p>
          <p className="mt-4 text-xs text-white/50">
            Acesse a aba <strong>Grupos</strong> para cadastrar a URL de incorporação do Power BI.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="relative h-full w-full bg-[var(--color-bg-dark)]">
      {isLoading && (
        <div className="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 bg-[var(--color-bg-dark)] p-6 text-white">
          <div className="h-10 w-10 border-3 border-[var(--color-accent-soft)] border-t-[var(--color-accent)] rounded-full animate-spin" />
          <p className="text-sm font-medium text-[var(--color-text-on-dark)]">
            Carregando o painel BI {groupName ? `(${groupName})` : "Ecoleta"}...
          </p>
        </div>
      )}

      <iframe
        key={iframeSrc}
        title={groupName ? `BI Ecoleta - ${groupName}` : "BI Ecoleta"}
        src={iframeSrc}
        className="h-full w-full border-0"
        allowFullScreen
        onLoad={() => setIsLoading(false)}
      />
    </div>
  );
}

