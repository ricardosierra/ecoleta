"use client";

import { useState, useRef } from "react";

const POWER_BI_SRC =
  "***POWERBI_URL_REMOVIDA***";

export function PowerBIViewer() {
  const [isLoading, setIsLoading] = useState(true);
  const [refreshKey, setRefreshKey] = useState(0);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  const handleRefresh = () => {
    setIsLoading(true);
    setRefreshKey((prev) => prev + 1);
  };

  const toggleFullscreen = async () => {
    if (!containerRef.current) return;

    if (!document.fullscreenElement) {
      try {
        await containerRef.current.requestFullscreen();
        setIsFullscreen(true);
      } catch (err) {
        console.error("Erro ao entrar em tela cheia:", err);
      }
    } else {
      try {
        await document.exitFullscreen();
        setIsFullscreen(false);
      } catch (err) {
        console.error("Erro ao sair de tela cheia:", err);
      }
    }
  };

  return (
    <div
      ref={containerRef}
      className={`relative flex flex-col w-full rounded-2xl overflow-hidden border border-[var(--color-border-dark)] bg-[var(--color-bg-dark)] shadow-xl transition-all duration-300 ${
        isFullscreen ? "h-screen rounded-none border-none" : "h-[calc(100vh-140px)] min-h-[580px]"
      }`}
    >
      {/* Barra de controle do Relatório */}
      <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 bg-[#08150A] border-b border-[var(--color-border-dark)] text-white text-sm">
        <div className="flex items-center gap-3">
          <span className="relative flex h-3 w-3">
            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-[var(--color-accent)] opacity-75"></span>
            <span className="relative inline-flex rounded-full h-3 w-3 bg-[var(--color-accent)]"></span>
          </span>
          <div className="flex flex-col">
            <h2 className="font-semibold text-white tracking-wide leading-tight">
              Bi Ecoleta — Relatório de Gestão & ESG
            </h2>
            <span className="text-xs text-[var(--color-text-on-dark)] opacity-75">
              Atualização dinâmica via Power BI
            </span>
          </div>
        </div>

        <div className="flex items-center gap-2">
          {/* Botão de Atualizar */}
          <button
            onClick={handleRefresh}
            title="Recarregar relatório"
            className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-full bg-[rgba(255,255,255,0.06)] hover:bg-[rgba(255,255,255,0.12)] text-[var(--color-text-on-dark)] hover:text-white transition-colors"
          >
            <svg
              className={`w-3.5 h-3.5 ${isLoading ? "animate-spin" : ""}`}
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
              />
            </svg>
            <span className="hidden sm:inline">Atualizar</span>
          </button>

          {/* Botão de Tela Cheia */}
          <button
            onClick={toggleFullscreen}
            title={isFullscreen ? "Sair da Tela Cheia" : "Modo Tela Cheia"}
            className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-full bg-[rgba(255,255,255,0.06)] hover:bg-[rgba(255,255,255,0.12)] text-[var(--color-text-on-dark)] hover:text-white transition-colors"
          >
            {isFullscreen ? (
              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M9 9L4 4m0 0l5 0m-5 0l0 5m11 0l5-5m0 0l-5 0m5 0l0 5m-5 11l5 5m0 0l-5 0m5 0l0-5m-11 0l-5 5m0 0l5 0m-5 0l0-5"
                />
              </svg>
            ) : (
              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M4 8V4m0 0h4M4 4l5 5m11-5h-4m4 0v4m0-4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"
                />
              </svg>
            )}
            <span className="hidden sm:inline">
              {isFullscreen ? "Sair da Tela Cheia" : "Tela Cheia"}
            </span>
          </button>

          {/* Link Externo Direct */}
          <a
            href={POWER_BI_SRC}
            target="_blank"
            rel="noopener noreferrer"
            title="Abrir diretamente no Power BI"
            className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-full bg-[var(--color-accent-soft)] text-[var(--color-accent)] hover:bg-[var(--color-accent)] hover:text-[var(--color-bg-dark)] transition-colors"
          >
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"
              />
            </svg>
            <span className="hidden md:inline">Abrir Guia</span>
          </a>
        </div>
      </div>

      {/* Conteúdo com Iframe e Skeleton Loader */}
      <div className="relative flex-1 w-full h-full bg-[#050D06]">
        {isLoading && (
          <div className="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 bg-[var(--color-bg-dark)] text-white p-6">
            <div className="w-10 h-10 border-3 border-[var(--color-accent-soft)] border-t-[var(--color-accent)] rounded-full animate-spin" />
            <p className="text-sm font-medium text-[var(--color-text-on-dark)]">
              Carregando o painel BI Ecoleta...
            </p>
          </div>
        )}

        <iframe
          key={refreshKey}
          title="Bi Ecoleta"
          src={POWER_BI_SRC}
          className="w-full h-full border-0"
          allowFullScreen={true}
          onLoad={() => setIsLoading(false)}
        />
      </div>
    </div>
  );
}
