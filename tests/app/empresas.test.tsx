import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import EmpresasPage from "@/app/dashboard/configuracoes/empresas/page";

const EMPRESAS = "/api/site/empresas.php";
const ME = "/api/auth/me.php";

const listaInicial = {
  ok: true,
  companies: [
    { id: 1, name: "Heineken", logo_url: "/logos/heineken.png", is_active: 1 },
    { id: 2, name: "CEDAE", logo_url: "/logos/cedae.png", is_active: 0 },
  ],
};

type Captura = { url: string; method: string; body: BodyInit | null | undefined };

/**
 * Mock de fetch que separa GET (lista) de POST (ações) no mesmo caminho, e
 * permite segurar a resposta do POST para observar o estado de carregamento.
 */
function instalaFetch(postBody: unknown = { ok: true }, postStatus = 200) {
  const capturas: Captura[] = [];
  let liberaPost: (() => void) | null = null;
  const postSegurado = new Promise<void>((resolve) => {
    liberaPost = resolve;
  });
  let segurarPost = false;

  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = typeof input === "string" ? input : input.toString();
    const method = (init?.method ?? "GET").toUpperCase();
    capturas.push({ url, method, body: init?.body });

    if (url.startsWith(ME)) {
      return Response.json({ ok: true, csrf_token: "a".repeat(64) });
    }

    if (url.startsWith(EMPRESAS) && method === "GET") {
      return Response.json(listaInicial);
    }

    if (url.startsWith(EMPRESAS) && method === "POST") {
      if (segurarPost) await postSegurado;
      return Response.json(postBody, { status: postStatus });
    }

    return Response.json({ error: "rota não prevista" }, { status: 403 });
  });

  vi.stubGlobal("fetch", fetchMock);

  return {
    fetchMock,
    capturas,
    seguraProximoPost: () => {
      segurarPost = true;
    },
    liberaPost: () => liberaPost?.(),
    posts: () => capturas.filter((c) => c.method === "POST" && c.url.startsWith(EMPRESAS)),
    gets: () => capturas.filter((c) => c.method === "GET" && c.url.startsWith(EMPRESAS)),
  };
}

beforeEach(() => {
  // jsdom não implementa object URLs; o preview da logo depende dos dois.
  vi.stubGlobal("URL", Object.assign(URL, {
    createObjectURL: vi.fn(() => "blob:mock-preview"),
    revokeObjectURL: vi.fn(),
  }));
});

describe("/dashboard/configuracoes/empresas", () => {
  it("lista as empresas cadastradas", async () => {
    instalaFetch();
    render(<EmpresasPage />);

    expect(await screen.findByText("Heineken")).toBeVisible();
    expect(screen.getByRole("button", { name: /Ativo/ })).toBeVisible();
    expect(screen.getByRole("button", { name: /Inativo/ })).toBeVisible();
  });

  it("mostra o carregando durante o toggle e atualiza a linha sem recarregar a lista", async () => {
    const api = instalaFetch({ ok: true, id: 1, is_active: 0 });
    api.seguraProximoPost();

    render(<EmpresasPage />);
    const botao = await screen.findByRole("button", { name: /^Ativo/ });

    const user = userEvent.setup();
    await user.click(botao);

    // Enquanto o POST não responde: spinner visível e botão desabilitado.
    expect(await screen.findByRole("status", { name: "Alterando status" })).toBeInTheDocument();
    expect(screen.getByText("Alterando...")).toBeInTheDocument();

    api.liberaPost();

    await waitFor(() => {
      expect(screen.queryByText("Alterando...")).not.toBeInTheDocument();
    });

    // A linha da Heineken virou Inativo localmente…
    expect(screen.getAllByRole("button", { name: /Inativo/ })).toHaveLength(2);
    // …sem um segundo GET da lista.
    expect(api.gets()).toHaveLength(1);
  });

  it("cadastra com upload mandando multipart com o arquivo", async () => {
    const api = instalaFetch({ ok: true, id: 3, logo_url: "/uploads/logos/vibra-abc123.png" });
    render(<EmpresasPage />);
    await screen.findByText("Heineken");

    const user = userEvent.setup();
    await user.click(screen.getByRole("button", { name: "+ Nova Empresa" }));
    await user.type(screen.getByLabelText(/Nome da Empresa/), "Vibra");

    const arquivo = new File(["png-fake"], "vibra.png", { type: "image/png" });
    await user.upload(screen.getByLabelText(/Imagem da Logo/), arquivo);

    expect(await screen.findByAltText("Prévia da logo")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Salvar Empresa" }));

    await waitFor(() => {
      expect(screen.getByText("Empresa cadastrada com sucesso!")).toBeVisible();
    });

    const post = api.posts()[0];
    expect(post.body).toBeInstanceOf(FormData);
    const form = post.body as FormData;
    expect(form.get("action")).toBe("create");
    expect(form.get("name")).toBe("Vibra");
    expect(form.get("logo")).toBeInstanceOf(File);
  });

  it("sem arquivo e sem caminho, avisa e não chama a API", async () => {
    const api = instalaFetch();
    render(<EmpresasPage />);
    await screen.findByText("Heineken");

    const user = userEvent.setup();
    await user.click(screen.getByRole("button", { name: "+ Nova Empresa" }));
    await user.type(screen.getByLabelText(/Nome da Empresa/), "Sem Logo");
    await user.click(screen.getByRole("button", { name: "Salvar Empresa" }));

    expect(await screen.findByText("Envie a imagem da logo ou informe um caminho.")).toBeVisible();
    expect(api.posts()).toHaveLength(0);
  });
});
