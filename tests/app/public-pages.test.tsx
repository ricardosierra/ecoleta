import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import PrivacyPolicyPage from "@/app/politica-de-privacidade/page";
import sitemap from "@/app/sitemap";
import { siteConfig } from "@/lib/site.config";

/**
 * Páginas públicas que não são o dashboard: o que o sitemap anuncia aos
 * buscadores e a data que a política de privacidade mostra.
 */

afterEach(() => {
  vi.useRealTimers();
});

describe("sitemap", () => {
  it("anuncia a política de privacidade", () => {
    const base = siteConfig.url.replace(/\/$/, "");

    const urls = sitemap().map((entrada) => entrada.url);

    expect(urls).toContain(`${base}/politica-de-privacidade`);
  });

  it("continua anunciando as páginas principais", () => {
    const base = siteConfig.url.replace(/\/$/, "");

    const urls = sitemap().map((entrada) => entrada.url);

    for (const caminho of ["/", "/solucoes", "/esg", "/sobre", "/contato", "/conteudos"]) {
      expect(urls).toContain(`${base}${caminho}`);
    }
  });

  it("a data de modificação da política é a da última revisão, não a do build", () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2031-05-17T12:00:00Z"));

    const politica = sitemap().find((entrada) => String(entrada.url).endsWith("/politica-de-privacidade"));

    expect(politica).toBeDefined();
    expect(new Date(politica?.lastModified as Date).toISOString().slice(0, 10)).toBe(
      siteConfig.legal.privacyPolicyUpdatedAt
    );
  });

  it("não repete nenhuma URL", () => {
    const urls = sitemap().map((entrada) => entrada.url);

    expect(new Set(urls).size).toBe(urls.length);
  });
});

describe("política de privacidade", () => {
  it("mostra a data fixa da última atualização, e não a de hoje", () => {
    // Com new Date() a data mudava a cada deploy e afirmava uma revisão que
    // nunca aconteceu.
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2031-05-17T12:00:00Z"));

    render(<PrivacyPolicyPage />);

    const [ano, mes, dia] = siteConfig.legal.privacyPolicyUpdatedAt.split("-");
    expect(screen.getByText(`Última atualização: ${dia}/${mes}/${ano}`)).toBeInTheDocument();
    expect(screen.queryByText(/17\/05\/2031/)).toBeNull();
  });

  it("a data não depende do fuso de quem faz o build", () => {
    // Meia-noite UTC cai no dia anterior em fusos a oeste; a data é só texto.
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2031-05-17T00:30:00Z"));
    const [ano, mes, dia] = siteConfig.legal.privacyPolicyUpdatedAt.split("-");

    render(<PrivacyPolicyPage />);

    expect(screen.getByText(`Última atualização: ${dia}/${mes}/${ano}`)).toBeInTheDocument();
  });
});
