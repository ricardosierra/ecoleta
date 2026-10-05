import { render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { DynamicClients } from "@/components/DynamicClients";

/**
 * O carrossel de clientes da home lê /api/site/empresas.php. A API só devolve
 * empresa ativa para o visitante; o que este componente decide é o que fazer
 * com a resposta: lista vazia significa "o operador tirou todas do site", e só
 * erro de rede ou de servidor justifica a lista de reserva embutida no código.
 */

const LISTA = "Clientes atendidos pela Ecoleva";

function instalaFetch(resposta: () => Promise<Response>) {
  const fetchMock = vi.fn(async () => resposta());
  vi.stubGlobal("fetch", fetchMock);
  return fetchMock;
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("DynamicClients", () => {
  it("mostra as empresas que a API devolveu", async () => {
    instalaFetch(async () =>
      Response.json({
        ok: true,
        companies: [
          { id: 1, name: "Vibra", logo_url: "/logos/vibra.png", is_active: 1 },
          { id: 2, name: "CEDAE", logo_url: "/logos/cedae.png", is_active: 1 },
        ],
      })
    );

    render(<DynamicClients />);

    expect(await screen.findByRole("list", { name: LISTA })).toBeInTheDocument();
    expect(screen.getByAltText("Vibra")).toBeInTheDocument();
    expect(screen.getByAltText("CEDAE")).toBeInTheDocument();
    expect(screen.queryByAltText("Heineken")).toBeNull();
  });

  it("continua ocultando as inativas quando quem navega é um admin logado", async () => {
    // O cookie de sessão vai em toda chamada a /api/, então um admin que abre a
    // home recebe a lista completa do painel. A tela pública não pode mostrar
    // o que ele mesmo tirou do site.
    instalaFetch(async () =>
      Response.json({
        ok: true,
        companies: [
          { id: 1, name: "Vibra", logo_url: "/logos/vibra.png", is_active: 1 },
          { id: 2, name: "Escondida", logo_url: "/logos/escondida.png", is_active: 0 },
        ],
      })
    );

    render(<DynamicClients />);

    expect(await screen.findByAltText("Vibra")).toBeInTheDocument();
    expect(screen.queryByAltText("Escondida")).toBeNull();
  });

  it("não mostra nada quando todas as empresas foram tiradas do site", async () => {
    // Com a API filtrando no servidor, "todas desativadas" chega como lista
    // vazia. Cair na lista de reserva aqui exibiria Heineken e LIESA justamente
    // depois de o operador ter tirado tudo do ar.
    const fetchMock = instalaFetch(async () => Response.json({ ok: true, companies: [] }));

    const { container } = render(<DynamicClients />);

    await waitFor(() => expect(fetchMock).toHaveBeenCalled());
    await waitFor(() => expect(container).toBeEmptyDOMElement());
    expect(screen.queryByAltText("Heineken")).toBeNull();
    expect(screen.queryByAltText("LIESA")).toBeNull();
  });

  it("usa a lista de reserva quando a API não responde", async () => {
    instalaFetch(async () => {
      throw new TypeError("Failed to fetch");
    });

    render(<DynamicClients />);

    expect(await screen.findByAltText("Heineken")).toBeInTheDocument();
    expect(screen.getByAltText("LIESA")).toBeInTheDocument();
  });

  it("usa a lista de reserva quando o servidor responde com erro", async () => {
    instalaFetch(async () => Response.json({ error: "Erro interno." }, { status: 500 }));

    render(<DynamicClients />);

    expect(await screen.findByAltText("Heineken")).toBeInTheDocument();
  });

  it("usa a lista de reserva quando a resposta não é JSON (página de erro da hospedagem)", async () => {
    instalaFetch(async () => new Response("<html>502 Bad Gateway</html>", { status: 502 }));

    render(<DynamicClients />);

    expect(await screen.findByAltText("Heineken")).toBeInTheDocument();
  });
});
