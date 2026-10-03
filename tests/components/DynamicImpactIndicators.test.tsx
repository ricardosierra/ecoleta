import { render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { DynamicImpactIndicators } from "@/components/DynamicImpactIndicators";

/**
 * Os seis números reais da página ESG, lidos de /api/site/indicadores.php. O
 * operador edita valor e rótulo no painel, então nada impede dois indicadores de
 * terminarem com o MESMO rótulo; a lista não pode se perder por causa disso.
 */

function instalaFetch(resposta: () => Promise<Response>) {
  vi.stubGlobal("fetch", vi.fn(async () => resposta()));
}

beforeEach(() => {
  // O Reveal consulta matchMedia, que o jsdom não implementa.
  vi.stubGlobal(
    "matchMedia",
    vi.fn(() => ({
      matches: false,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
  );
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("DynamicImpactIndicators", () => {
  it("mostra os indicadores que a API devolveu", async () => {
    instalaFetch(async () =>
      Response.json({
        ok: true,
        indicators: [
          { key: "pessoas", value: "350 Mil", label: "Pessoas atendidas", symbol_type: "icon", symbol_value: "ImpactPeopleIcon" },
          { key: "co2", value: "200 tCO₂e", label: "CO₂ evitado", symbol_type: "icon", symbol_value: "ImpactCarbonIcon" },
        ],
      })
    );

    render(<DynamicImpactIndicators />);

    expect(await screen.findByText("350 Mil")).toBeInTheDocument();
    expect(screen.getByText("Pessoas atendidas")).toBeInTheDocument();
    expect(screen.getByText("200 tCO₂e")).toBeInTheDocument();
    // O fallback embutido sai de cena quando a API responde.
    expect(screen.queryByText("300 Mil")).toBeNull();
  });

  it("mantém todos os indicadores quando dois ficam com o mesmo rótulo, sem aviso de chave duplicada", async () => {
    const erros = vi.spyOn(console, "error").mockImplementation(() => {});
    instalaFetch(async () =>
      Response.json({
        ok: true,
        indicators: [
          { key: "pessoas", value: "350 Mil", label: "Impacto", symbol_type: "icon", symbol_value: "ImpactPeopleIcon" },
          { key: "co2", value: "200 tCO₂e", label: "Impacto", symbol_type: "icon", symbol_value: "ImpactCarbonIcon" },
          { key: "agua", value: "9 Mi", label: "Impacto", symbol_type: "icon", symbol_value: "ImpactWaterIcon" },
        ],
      })
    );

    render(<DynamicImpactIndicators />);

    await waitFor(() => expect(screen.getAllByText("Impacto")).toHaveLength(3));
    expect(screen.getByText("350 Mil")).toBeInTheDocument();
    expect(screen.getByText("200 tCO₂e")).toBeInTheDocument();
    expect(screen.getByText("9 Mi")).toBeInTheDocument();

    // O React avisa com "Encountered two children with the same key" quando a
    // chave repete; com chave repetida ele também pode descartar ou duplicar
    // itens ao atualizar a lista.
    const avisoDeChave = erros.mock.calls.some((args) => String(args[0]).includes("same key"));
    expect(avisoDeChave, "chave de lista repetida").toBe(false);
  });

  it("ignora indicador cujo ícone o site não conhece", async () => {
    instalaFetch(async () =>
      Response.json({
        ok: true,
        indicators: [
          { key: "pessoas", value: "350 Mil", label: "Pessoas atendidas", symbol_type: "icon", symbol_value: "ImpactPeopleIcon" },
          { key: "estranho", value: "1", label: "Sem ícone", symbol_type: "icon", symbol_value: "IconeQueNaoExiste" },
        ],
      })
    );

    render(<DynamicImpactIndicators />);

    expect(await screen.findByText("Pessoas atendidas")).toBeInTheDocument();
    expect(screen.queryByText("Sem ícone")).toBeNull();
  });

  it("fica com os números de reserva quando a API falha", async () => {
    instalaFetch(async () => {
      throw new TypeError("Failed to fetch");
    });

    render(<DynamicImpactIndicators />);

    expect(await screen.findByText("Pessoas impactadas")).toBeInTheDocument();
    expect(screen.getByText("Litros de água poupada")).toBeInTheDocument();
  });
});
