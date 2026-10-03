import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import OSPage from "@/app/dashboard/os/page";
import { installApiMock, sessionOf, type ApiRoutes } from "../support/api-mock";

const ME = "/api/auth/me.php";
const CLIENTS = "/api/clients/index.php";
const OS = "/api/os/index.php";
const SEND = "/api/os/send.php";
const WHATSAPP = "/api/os/whatsapp.php";

const ordem = {
  id: 42,
  client_id: 1,
  client_name: "Heineken",
  client_email: "contato@heineken.exemplo",
  client_whatsapp: "5521999887766",
  collection_address: "Av. das Américas, 500",
  weight: "150 kg",
  collection_date: "2026-09-03",
  approximate_time: "14:30",
  material_collected: "Óleo vegetal usado",
  bags_count: 12,
  containers_count: 2,
  responsible: "Equipe A",
  signature_text: "Responsável Técnica - ECOLEVA",
  sent_at: null,
  sent_to: null,
  whatsapp_sent_at: null,
  whatsapp_sent_to: null,
  share_url: "https://ecolevaeco.com/api/os/view.php?id=42&t=abc",
};

function montar(rotas: ApiRoutes = {}) {
  return installApiMock({
    [ME]: { body: sessionOf("root") },
    [CLIENTS]: { body: { ok: true, clients: [{ id: 1, name: "Heineken" }] } },
    [OS]: { body: { ok: true, service_orders: [ordem] } },
    ...rotas,
  });
}

/** Abre a OS do histórico na pré-visualização. */
async function abrirOS(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole("button", { name: "Visualizar" }));
}

describe("/dashboard/os — encaminhamento", () => {
  beforeEach(() => {
    vi.unstubAllGlobals();
  });

  it("mostra os campos de endereço, horário aproximado e material coletado no documento", async () => {
    montar();
    render(<OSPage />);
    await abrirOS(userEvent.setup());

    const documento = document.querySelector("#os-print-area");
    expect(documento?.textContent).toContain("Endereço da coleta: Av. das Américas, 500");
    expect(documento?.textContent).toContain("Horário aproximado: 14:30");
    expect(documento?.textContent).toContain("Material coletado: Óleo vegetal usado");
  });

  /**
   * A pré-visualização tem de mostrar o que o cliente recebe: os rótulos, a
   * ordem e o marcador de vazio do documento do PHP (os_lib.php), e não uma
   * redação própria. Estas são as mesmas linhas de tests/php/Unit/OsLibTest.php.
   */
  it("mostra os campos na ordem e com os rótulos do documento que o cliente recebe", async () => {
    montar();
    render(<OSPage />);
    await abrirOS(userEvent.setup());

    const documento = document.querySelector("#os-print-area");
    expect(documento?.textContent).toContain(
      [
        "Cliente: Heineken",
        "Endereço da coleta: Av. das Américas, 500",
        "Data da coleta: 03/09/2026",
        "Horário aproximado: 14:30",
        "Material coletado: Óleo vegetal usado",
        "Pesagem: 150 kg",
        "Responsável pela coleta: Equipe A",
        "Qtd. sacos: 12",
        "Qtd. contêineres: 2",
      ].join("")
    );
  });

  it("marca os campos vazios com hífen, como o documento do cliente", async () => {
    montar({
      [OS]: {
        body: {
          ok: true,
          service_orders: [
            { ...ordem, collection_address: null, weight: "", collection_date: null, bags_count: null, responsible: "  " },
          ],
        },
      },
    });
    render(<OSPage />);
    await abrirOS(userEvent.setup());

    const texto = document.querySelector("#os-print-area")?.textContent ?? "";
    expect(texto).toContain("Endereço da coleta: -");
    expect(texto).toContain("Data da coleta: -");
    expect(texto).toContain("Pesagem: -");
    expect(texto).toContain("Responsável pela coleta: -");
    expect(texto).toContain("Qtd. sacos: -");
    expect(texto).not.toContain("—");
  });

  it("oferece o mesmo WhatsApp de suporte que o documento do PHP, com a mesma frase", async () => {
    montar();
    render(<OSPage />);
    await abrirOS(userEvent.setup());

    const documento = document.querySelector("#os-print-area");
    expect(documento?.textContent).toContain(
      "Caso precise de suporte ou esclarecimentos, envie mensagem para nosso WhatsApp: (21) 99152-9383"
    );
  });

  /**
   * Quem decide é o servidor (osValidateInput() em os_lib.php), mas o formulário
   * já avisa antes: o campo não deixa passar do tamanho da coluna nem do teto.
   */
  it("limita o tamanho dos campos do formulário como o servidor", async () => {
    montar();
    render(<OSPage />);
    await screen.findByLabelText(/Pesagem/);

    expect(screen.getByLabelText(/Pesagem/)).toHaveAttribute("maxlength", "50");
    expect(screen.getByLabelText(/Horário Aproximado/)).toHaveAttribute("maxlength", "50");
    expect(screen.getByLabelText(/Endereço da Coleta/)).toHaveAttribute("maxlength", "255");
    expect(screen.getByLabelText(/Material Coletado/)).toHaveAttribute("maxlength", "255");
    expect(screen.getByLabelText(/Responsável pela Coleta/)).toHaveAttribute("maxlength", "255");
    expect(screen.getByLabelText(/Qtd\. Sacos/)).toHaveAttribute("max", "99999");
    expect(screen.getByLabelText(/Qtd\. Contêineres/)).toHaveAttribute("max", "99999");
  });

  it("mostra o erro do servidor, que nomeia o campo, ao gerar a OS", async () => {
    // A listagem (GET) carrega normalmente; só a criação (POST) é recusada.
    const api = montar();
    const original = api.fetch.getMockImplementation()!;
    api.fetch.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      if (String(input).startsWith(OS) && init?.method === "POST") {
        return Response.json({ error: "Qtd. sacos deve ser um número inteiro de 0 a 99999." }, { status: 400 });
      }
      return original(input, init);
    });
    render(<OSPage />);

    const user = userEvent.setup();
    // Os clientes chegam pela API; o <option> só existe depois disso.
    await screen.findByRole("option", { name: "Heineken" });
    await user.selectOptions(screen.getByLabelText(/Cliente \*/), "1");
    await user.click(screen.getByRole("button", { name: "Gerar OS" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("Qtd. sacos deve ser um número inteiro de 0 a 99999.");
  });

  it("mostra a assinatura da responsável no documento", async () => {
    montar();
    render(<OSPage />);
    await abrirOS(userEvent.setup());

    const assinatura = document.querySelector('img[src*="assinatura-responsavel"]');
    expect(assinatura).not.toBeNull();
    expect(screen.getByText("Responsável Técnica - ECOLEVA")).toBeVisible();
  });

  it("formata a data da coleta sem deslocar o dia", async () => {
    montar();
    render(<OSPage />);
    await abrirOS(userEvent.setup());

    // A data aparece no documento e na linha do histórico: as duas leem o mesmo
    // formatador, e nenhuma pode voltar para 02/09 por conta do fuso.
    await waitFor(() => {
      expect(screen.getAllByText(/03\/09\/2026/)).toHaveLength(2);
    });

    const documento = document.querySelector("#os-print-area");
    expect(documento?.textContent).toContain("Data da coleta: 03/09/2026");
  });

  it("envia por e-mail para o endereço do cliente, já preenchido", async () => {
    const api = montar({
      [SEND]: { body: { ok: true, sent_to: "contato@heineken.exemplo", sent_at: "2026-09-03 14:22:00" } },
    });
    render(<OSPage />);

    const user = userEvent.setup();
    await abrirOS(user);

    expect(screen.getByLabelText("E-mail do destinatário")).toHaveValue("contato@heineken.exemplo");
    await user.click(screen.getByRole("button", { name: "E-mail" }));

    await waitFor(() => {
      expect(screen.getByRole("status")).toHaveTextContent("Enviada para contato@heineken.exemplo.");
    });

    const post = api.fetch.mock.calls.find(([url]) => String(url) === SEND);
    expect(JSON.parse(String(post![1]!.body))).toEqual({ id: 42, email: "contato@heineken.exemplo" });
  });

  it("mostra o erro devolvido pelo servidor no envio por e-mail", async () => {
    montar({ [SEND]: { status: 400, body: { error: "E-mail de destino inválido." } } });
    render(<OSPage />);

    const user = userEvent.setup();
    await abrirOS(user);
    await user.click(screen.getByRole("button", { name: "E-mail" }));

    await waitFor(() => {
      expect(screen.getByRole("status")).toHaveTextContent("E-mail de destino inválido.");
    });
  });

  it("abre o WhatsApp pessoal com o texto e o link prontos", async () => {
    montar();
    const open = vi.fn();
    vi.stubGlobal("open", open);
    render(<OSPage />);

    const user = userEvent.setup();
    await abrirOS(user);
    await user.click(screen.getByRole("button", { name: "Meu WhatsApp" }));

    expect(open).toHaveBeenCalledTimes(1);
    const url = String(open.mock.calls[0][0]);
    expect(url.startsWith("https://wa.me/5521999887766?text=")).toBe(true);
    expect(decodeURIComponent(url)).toContain("https://ecolevaeco.com/api/os/view.php?id=42&t=abc");
  });

  it("dispara o WhatsApp do robô sem confirmar quando a OS ainda não foi enviada", async () => {
    const api = montar({
      [WHATSAPP]: {
        body: { ok: true, whatsapp_sent_to: "5521999887766", whatsapp_sent_at: "2026-09-03 14:22:00" },
      },
    });
    render(<OSPage />);

    const user = userEvent.setup();
    await abrirOS(user);
    await user.click(screen.getByRole("button", { name: "WhatsApp do robô" }));

    await waitFor(() => {
      expect(screen.getByRole("status")).toHaveTextContent("Aceita pela Meta para 5521999887766.");
    });

    const post = api.fetch.mock.calls.find(([url]) => String(url) === WHATSAPP);
    expect(JSON.parse(String(post![1]!.body))).toEqual({ id: 42, confirm: false });
    expect(screen.queryByRole("dialog")).toBeNull();
  });

  it("pede confirmação antes de reenviar uma OS que o robô já mandou", async () => {
    const api = montar({
      [WHATSAPP]: {
        status: 409,
        body: {
          error: "Esta OS já foi enviada pelo WhatsApp do robô.",
          code: "whatsapp_already_sent",
          whatsapp_sent_at: "2026-09-03 14:22:00",
          whatsapp_sent_to: "5521999887766",
        },
      },
    });
    render(<OSPage />);

    const user = userEvent.setup();
    await abrirOS(user);
    await user.click(screen.getByRole("button", { name: "WhatsApp do robô" }));

    const dialogo = await screen.findByRole("dialog");
    expect(dialogo).toHaveTextContent("OS Nº 00042 já enviada");
    expect(dialogo).toHaveTextContent("5521999887766");
    expect(dialogo).toHaveTextContent("03/09/2026 14:22");

    // O reenvio só sai com `confirm: true` — a primeira chamada não gravou nada.
    await user.click(screen.getByRole("button", { name: "Enviar novamente" }));

    await waitFor(() => {
      const posts = api.fetch.mock.calls.filter(([url]) => String(url) === WHATSAPP);
      expect(JSON.parse(String(posts[1]![1]!.body))).toEqual({ id: 42, confirm: true });
    });
  });

  it("fecha a confirmação sem reenviar quando o operador cancela", async () => {
    const api = montar({
      [WHATSAPP]: {
        status: 409,
        body: { code: "whatsapp_already_sent", whatsapp_sent_at: "2026-09-03 14:22:00", whatsapp_sent_to: "5521999887766" },
      },
    });
    render(<OSPage />);

    const user = userEvent.setup();
    await abrirOS(user);
    await user.click(screen.getByRole("button", { name: "WhatsApp do robô" }));
    await user.click(await screen.findByRole("button", { name: "Cancelar" }));

    expect(screen.queryByRole("dialog")).toBeNull();
    expect(api.fetch.mock.calls.filter(([url]) => String(url) === WHATSAPP)).toHaveLength(1);
  });

  it("avisa quando o robô não está configurado no servidor", async () => {
    montar({
      [WHATSAPP]: {
        status: 503,
        body: { error: "O WhatsApp do robô não está configurado neste servidor.", code: "whatsapp_not_configured" },
      },
    });
    render(<OSPage />);

    const user = userEvent.setup();
    await abrirOS(user);
    await user.click(screen.getByRole("button", { name: "WhatsApp do robô" }));

    await waitFor(() => {
      expect(screen.getByRole("status")).toHaveTextContent("não está configurado");
    });
    expect(screen.queryByRole("dialog")).toBeNull();
  });
});

describe("/dashboard/os — falha ao carregar a tela", () => {
  /**
   * Sessão vencida, 503 de schema ou rede caída: o histórico mostrava o estado
   * vazio ("Nenhuma OS encontrada.") e o select de clientes ficava vazio, sem
   * nenhum aviso de que algo tinha dado errado.
   */
  it("avisa que o histórico não carregou, em vez de dizer que não há OS", async () => {
    montar({ [OS]: { status: 503, body: { error: "O banco de dados está desatualizado." } } });
    render(<OSPage />);

    const aviso = await screen.findByRole("alert");
    expect(aviso).toHaveTextContent("Não foi possível carregar");
    expect(aviso).toHaveTextContent("Histórico de OS: O banco de dados está desatualizado.");
    expect(screen.queryByText("Nenhuma OS encontrada.")).toBeNull();
    expect(screen.getByText("Histórico indisponível.")).toBeVisible();
  });

  it("avisa que a lista de clientes não carregou", async () => {
    montar({ [CLIENTS]: { status: 403, body: { error: "Acesso negado." } } });
    render(<OSPage />);

    const aviso = await screen.findByRole("alert");
    expect(aviso).toHaveTextContent("Lista de clientes: Acesso negado.");
    // O histórico carregou normalmente e continua na tela.
    expect(await screen.findByText("#00042")).toBeVisible();
  });

  it("explica a rede caída pelo nome, sem mostrar o texto técnico do navegador", async () => {
    const api = montar();
    const original = api.fetch.getMockImplementation()!;
    api.fetch.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      if (String(input).startsWith(OS)) throw new TypeError("Failed to fetch");
      return original(input, init);
    });
    render(<OSPage />);

    const aviso = await screen.findByRole("alert");
    expect(aviso).toHaveTextContent("Histórico de OS: Sem conexão com o servidor.");
    expect(aviso).not.toHaveTextContent("Failed to fetch");
    expect(screen.queryByText("Nenhuma OS encontrada.")).toBeNull();
  });

  it("trata resposta que não é JSON (página de erro do servidor) como falha de carga", async () => {
    const api = montar();
    const original = api.fetch.getMockImplementation()!;
    api.fetch.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      if (String(input).startsWith(OS)) return new Response("<html>Erro 500</html>", { status: 500 });
      return original(input, init);
    });
    render(<OSPage />);

    const aviso = await screen.findByRole("alert");
    expect(aviso).toHaveTextContent("Histórico de OS: O servidor respondeu 500.");
  });

  it("recarrega ao clicar em tentar novamente e limpa o aviso quando dá certo", async () => {
    const api = montar();
    const original = api.fetch.getMockImplementation()!;
    let caiu = true;
    api.fetch.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      if (caiu && String(input).startsWith(OS)) throw new TypeError("Failed to fetch");
      return original(input, init);
    });
    render(<OSPage />);

    const user = userEvent.setup();
    await screen.findByRole("alert");

    caiu = false;
    await user.click(screen.getByRole("button", { name: "Tentar novamente" }));

    expect(await screen.findByText("#00042")).toBeVisible();
    await waitFor(() => expect(screen.queryByText(/Não foi possível carregar/)).toBeNull());
  });

  it("mostra 'Carregando' enquanto o histórico não chegou, e não 'Nenhuma OS encontrada.'", async () => {
    const api = montar();
    const original = api.fetch.getMockImplementation()!;
    api.fetch.mockImplementation((input: RequestInfo | URL, init?: RequestInit) =>
      String(input).startsWith(OS) ? new Promise<Response>(() => {}) : original(input, init)
    );
    render(<OSPage />);

    expect(await screen.findByText("Carregando…")).toBeVisible();
    expect(screen.queryByText("Nenhuma OS encontrada.")).toBeNull();
  });

  it("continua dizendo 'Nenhuma OS encontrada.' quando o servidor responde com a lista vazia", async () => {
    montar({ [OS]: { body: { ok: true, service_orders: [] } } });
    render(<OSPage />);

    expect(await screen.findByText("Nenhuma OS encontrada.")).toBeVisible();
    expect(screen.queryByRole("alert")).toBeNull();
  });
});

describe("/dashboard/os — o que aconteceu com a mensagem do robô", () => {
  const enviada = {
    whatsapp_sent_at: "2026-09-03 14:22:00",
    whatsapp_sent_to: "5521999887766",
  };

  const comStatus = (id: number, status: string | null, extra: object = {}) => ({
    ...ordem,
    id,
    client_name: `Cliente ${id}`,
    ...enviada,
    whatsapp_status: status,
    ...extra,
  });

  /**
   * Número fixo ou sem WhatsApp falha DEPOIS de a Meta aceitar o pedido, pelo
   * webhook. A tela só sabia de `whatsapp_sent_at` e mostrava sempre o selo verde
   * de "enviada".
   */
  it("mostra no histórico o que a Meta informou de cada envio, e não sempre 'enviada'", async () => {
    montar({
      [OS]: {
        body: {
          ok: true,
          service_orders: [
            comStatus(1, "accepted"),
            comStatus(2, "delivered"),
            comStatus(3, "read"),
            comStatus(4, "failed", { whatsapp_error: "Message undeliverable" }),
          ],
        },
      },
    });
    render(<OSPage />);

    expect(await screen.findByText("Aceita pela Meta")).toBeVisible();
    expect(screen.getByText("Entregue")).toBeVisible();
    expect(screen.getByText("Lida")).toBeVisible();
    expect(screen.getByText("Falhou")).toBeVisible();
    expect(screen.queryByText(/^Enviada$/)).toBeNull();
  });

  it("pinta a falha de vermelho e leva o motivo da Meta no tooltip", async () => {
    montar({
      [OS]: { body: { ok: true, service_orders: [comStatus(4, "failed", { whatsapp_error: "Message undeliverable" })] } },
    });
    render(<OSPage />);

    const selo = (await screen.findByText("Falhou")).closest("span[title]")!;
    expect(selo.className).toContain("red");
    expect(selo.getAttribute("title")).toContain("Message undeliverable");
    expect(selo.getAttribute("title")).toContain("5521999887766");
  });

  it("não pinta de verde o que a Meta só aceitou", async () => {
    montar({
      [OS]: { body: { ok: true, service_orders: [comStatus(1, "accepted"), comStatus(2, "delivered")] } },
    });
    render(<OSPage />);

    const aceita = (await screen.findByText("Aceita pela Meta")).closest("span[title]")!;
    const entregue = screen.getByText("Entregue").closest("span[title]")!;
    expect(aceita.className).not.toContain("accent");
    expect(entregue.className).toContain("accent");
  });

  it("mantém 'Enviada' só para a OS antiga, enviada antes de o status ser guardado", async () => {
    montar({ [OS]: { body: { ok: true, service_orders: [comStatus(1, null)] } } });
    render(<OSPage />);

    expect(await screen.findByText("Enviada")).toBeVisible();
  });

  it("não mostra selo de WhatsApp para a OS que o robô nunca enviou", async () => {
    montar();
    render(<OSPage />);

    await screen.findByText("#00042");
    expect(screen.queryByText("Aceita pela Meta")).toBeNull();
    expect(screen.queryByText("Enviada")).toBeNull();
  });

  it("mostra o status também na pré-visualização da OS", async () => {
    montar({ [OS]: { body: { ok: true, service_orders: [comStatus(7, "delivered")] } } });
    render(<OSPage />);
    await abrirOS(userEvent.setup());

    // O <dt> do robô guarda o nome só para leitor de tela (sr-only).
    const envios = screen.getByText("WhatsApp do robô", { selector: ".sr-only" }).closest("div")!;
    expect(envios).toHaveTextContent("5521999887766");
    expect(envios).toHaveTextContent("Entregue");
  });

  it("depois de enviar pelo robô, a OS passa a constar como aceita pela Meta, não como entregue", async () => {
    montar({
      [WHATSAPP]: {
        body: {
          ok: true,
          whatsapp_sent_to: "5521999887766",
          whatsapp_sent_at: "2026-09-03 14:22:00",
          whatsapp_status: "accepted",
        },
      },
    });
    render(<OSPage />);

    const user = userEvent.setup();
    await abrirOS(user);
    await user.click(screen.getByRole("button", { name: "WhatsApp do robô" }));

    await waitFor(() => {
      expect(screen.getByRole("status")).toHaveTextContent("A entrega é confirmada em seguida");
    });
    // Uma vez na linha do histórico e outra na pré-visualização.
    expect(screen.getAllByText("Aceita pela Meta")).toHaveLength(2);
    expect(screen.queryByText("Entregue")).toBeNull();
  });
});

describe("/dashboard/os — janela de 24h do WhatsApp", () => {
  const comJanela = (window: unknown) => ({
    ...ordem,
    whatsapp_window: window,
  });

  const abrirCom = async (window: unknown) => {
    installApiMock({
      [ME]: { body: sessionOf("root") },
      [CLIENTS]: { body: { ok: true, clients: [{ id: 1, name: "Heineken" }] } },
      [OS]: { body: { ok: true, service_orders: [comJanela(window)] } },
    });
    render(<OSPage />);
    await abrirOS(userEvent.setup());

    return screen.getByRole("button", { name: "WhatsApp do robô" });
  };

  /**
   * Verde forte = janela aberta = envio gratuito. É o sinal que o usuário pediu
   * para conseguir decidir de longe se vale apertar agora.
   */
  it("pinta o botão de verde dentro da janela", async () => {
    const botao = await abrirCom({ open: true, expires_at: null, minutes_left: 300 });

    expect(botao.className).toContain("bg-[var(--color-accent)]");
    expect(botao).toHaveAttribute("title", expect.stringContaining("Dentro da janela de 24 horas"));
    expect(botao.getAttribute("title")).toContain("não cobra");
  });

  it("mantém o botão neutro fora da janela", async () => {
    const botao = await abrirCom({ open: false, expires_at: null, minutes_left: 0 });

    expect(botao.className).not.toContain("bg-[var(--color-accent)]");
    expect(botao.getAttribute("title")).toContain("Fora da janela de 24 horas");
    expect(botao.getAttribute("title")).toContain("cobrado");
  });

  it("trata cliente que nunca escreveu como fora da janela", async () => {
    const botao = await abrirCom(null);

    expect(botao.className).not.toContain("bg-[var(--color-accent)]");
    expect(botao.getAttribute("title")).toContain("nunca escreveu");
  });

  it("avisa quando a Meta recusa por estar fora da janela", async () => {
    montar({
      [WHATSAPP]: {
        status: 422,
        body: {
          error: "O cliente não escreve para este número há mais de 24 horas — fora da janela, a Meta só entrega template aprovado.",
          code: "whatsapp_outside_window",
        },
      },
    });
    render(<OSPage />);

    const user = userEvent.setup();
    await abrirOS(user);
    await user.click(screen.getByRole("button", { name: "WhatsApp do robô" }));

    await waitFor(() => {
      expect(screen.getByRole("status")).toHaveTextContent("mais de 24 horas");
    });
    expect(screen.queryByRole("dialog")).toBeNull();
  });
});
