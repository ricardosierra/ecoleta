import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";
import ContactForm from "@/components/ContactForm";

/**
 * O formulário de contato do site público. O envio real vai para
 * /contact.php (o site é export estático); aqui `fetch` é substituído e o que
 * se confere é o que o visitante, inclusive quem usa leitor de tela ou só o
 * teclado, encontra na página.
 */

const CAMPOS = {
  nome: /^Nome/,
  email: /^E-mail/,
  telefone: /^Telefone/,
  empresa: /^Empresa/,
  tipoOperacao: /^Tipo de operação/,
  mensagem: /^Mensagem/,
};

const valido = {
  nome: "Maria Silva",
  email: "maria@empresa.com.br",
  telefone: "11 98888-7777",
  empresa: "Empresa Exemplo",
  tipoOperacao: "Indústria",
  mensagem: "Gostaria de entender a coleta seletiva na nossa unidade fabril.",
};

async function preenche(user: ReturnType<typeof userEvent.setup>, dados: Partial<typeof valido>) {
  for (const [campo, valor] of Object.entries(dados) as [keyof typeof valido, string][]) {
    const el = screen.getByLabelText(CAMPOS[campo]);
    if (el.tagName === "SELECT") {
      await user.selectOptions(el, valor);
    } else {
      await user.type(el, valor);
    }
  }
}

const enviar = () => screen.getByRole("button", { name: /Enviar mensagem|Enviando/ });

function instalaFetch(resposta: () => Promise<Response>) {
  const fetchMock = vi.fn(async () => resposta());
  vi.stubGlobal("fetch", fetchMock);
  return fetchMock;
}

afterEach(() => {
  vi.useRealTimers();
});

describe("ContactForm: erros de validação", () => {
  it("liga cada mensagem de erro ao seu campo por aria-describedby", async () => {
    const user = userEvent.setup();
    render(<ContactForm />);

    await user.click(enviar());

    for (const campo of Object.keys(CAMPOS)) {
      const el = screen.getByLabelText(CAMPOS[campo as keyof typeof CAMPOS]);
      expect(el).toHaveAttribute("aria-invalid", "true");
      const idDoErro = el.getAttribute("aria-describedby");
      expect(idDoErro, `${campo} sem aria-describedby`).toBe(`${campo}-error`);
      expect(document.getElementById(idDoErro as string)).toHaveTextContent(/\S/);
    }
  });

  it("campo válido não aponta para erro nenhum", async () => {
    const user = userEvent.setup();
    render(<ContactForm />);
    await preenche(user, { nome: valido.nome });

    await user.click(enviar());

    expect(screen.getByLabelText(CAMPOS.nome)).not.toHaveAttribute("aria-describedby");
    expect(screen.getByLabelText(CAMPOS.nome)).toHaveAttribute("aria-invalid", "false");
  });

  it("leva o foco ao primeiro campo inválido, na ordem da tela", async () => {
    const user = userEvent.setup();
    render(<ContactForm />);
    await preenche(user, { nome: valido.nome });

    await user.click(enviar());

    expect(screen.getByLabelText(CAMPOS.email)).toHaveFocus();
  });

  it("leva o foco ao select e ao textarea quando são eles os inválidos", async () => {
    const user = userEvent.setup();
    render(<ContactForm />);
    await preenche(user, { nome: valido.nome, email: valido.email, telefone: valido.telefone, empresa: valido.empresa });

    await user.click(enviar());
    expect(screen.getByLabelText(CAMPOS.tipoOperacao)).toHaveFocus();

    await preenche(user, { tipoOperacao: valido.tipoOperacao });
    await user.click(enviar());
    expect(screen.getByLabelText(CAMPOS.mensagem)).toHaveFocus();
  });

  it("também leva o foco ao campo que o servidor apontou", async () => {
    const user = userEvent.setup();
    instalaFetch(async () =>
      Response.json(
        { error: "Dados inválidos.", issues: [{ path: "telefone", message: "Informe um telefone válido." }] },
        { status: 400 }
      )
    );
    render(<ContactForm />);
    await preenche(user, valido);

    await user.click(enviar());

    const telefone = await screen.findByLabelText(CAMPOS.telefone);
    await waitFor(() => expect(telefone).toHaveFocus());
    expect(telefone).toHaveAttribute("aria-describedby", "telefone-error");
    expect(document.getElementById("telefone-error")).toHaveTextContent("Informe um telefone válido.");
  });

  it("não usa o vermelho de 3,8:1: erros e asteriscos passam de 4,5:1 no branco", async () => {
    const user = userEvent.setup();
    const { container } = render(<ContactForm />);

    await user.click(enviar());

    // text-red-500 sobre branco dá cerca de 3,8:1; text-red-700, o vermelho
    // que a caixa de alerta do mesmo formulário já usa, passa de 6:1.
    expect(container.querySelector(".text-red-500")).toBeNull();
    expect(document.getElementById("nome-error")).toHaveClass("text-red-700");
    expect(document.getElementById("tipoOperacao-error")).toHaveClass("text-red-700");
    expect(document.getElementById("mensagem-error")).toHaveClass("text-red-700");
  });
});

describe("ContactForm: envio concluído", () => {
  it("anuncia o sucesso como status e leva o foco até ele", async () => {
    const user = userEvent.setup();
    instalaFetch(async () => Response.json({ ok: true }));
    render(<ContactForm />);
    await preenche(user, valido);

    await user.click(enviar());

    // O formulário some do DOM; sem mover o foco, quem usa teclado ou leitor de
    // tela ficaria no <body> sem saber que deu certo.
    const status = await screen.findByRole("status");
    expect(status).toHaveTextContent("Mensagem enviada com sucesso.");
    await waitFor(() => expect(status).toHaveFocus());
  });

  it("devolve o foco ao primeiro campo quando a pessoa quer enviar outra mensagem", async () => {
    const user = userEvent.setup();
    instalaFetch(async () => Response.json({ ok: true }));
    render(<ContactForm />);
    await preenche(user, valido);
    await user.click(enviar());

    await user.click(await screen.findByRole("button", { name: "Enviar outra mensagem" }));

    await waitFor(() => expect(screen.getByLabelText(CAMPOS.nome)).toHaveFocus());
  });
});

describe("ContactForm: servidor que não responde", () => {
  it("aborta o envio depois de 15 segundos e explica o que houve", async () => {
    const user = userEvent.setup();
    let sinal: AbortSignal | undefined;
    const fetchMock = vi.fn(
      (_input: RequestInfo | URL, init?: RequestInit) =>
        new Promise<Response>((_resolve, reject) => {
          sinal = init?.signal as AbortSignal;
          // Um fetch de verdade rejeita com AbortError quando o sinal dispara.
          sinal?.addEventListener("abort", () => reject(new DOMException("Aborted", "AbortError")));
        })
    );
    vi.stubGlobal("fetch", fetchMock);

    render(<ContactForm />);
    await preenche(user, valido);

    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    fireEvent.click(enviar());

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(sinal).toBeDefined();

    // O act deixa o React aplicar os setState que o timer dispara.
    await act(() => vi.advanceTimersByTimeAsync(14_900));
    expect(sinal?.aborted).toBe(false);
    expect(screen.queryByRole("alert")).toBeNull();
    expect(enviar()).toBeDisabled();

    await act(() => vi.advanceTimersByTimeAsync(200));
    expect(sinal?.aborted).toBe(true);

    const alerta = screen.getByRole("alert");
    expect(alerta).toHaveTextContent(/demorou/i);
    // O botão volta a funcionar e o que foi digitado continua lá.
    expect(enviar()).toBeEnabled();
    expect(screen.getByLabelText(CAMPOS.nome)).toHaveValue(valido.nome);
  });

  it("não deixa o cronômetro de 15 segundos vivo depois de uma resposta rápida", async () => {
    const user = userEvent.setup();
    const abort = vi.spyOn(AbortController.prototype, "abort");
    instalaFetch(async () => Response.json({ ok: true }));
    render(<ContactForm />);
    await preenche(user, valido);

    // Os timers falsos entram ANTES do clique: é o clique que arma o cronômetro.
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    fireEvent.click(enviar());
    await act(() => vi.advanceTimersByTimeAsync(50));
    expect(screen.getByRole("status")).toBeInTheDocument();

    await act(() => vi.advanceTimersByTimeAsync(20_000));

    expect(abort).not.toHaveBeenCalled();
  });
});

describe("ContactForm: consentimento", () => {
  it("o texto de consentimento linka a política de privacidade", () => {
    render(<ContactForm />);

    const link = screen.getByRole("link", { name: "Política de Privacidade" });

    expect(link).toHaveAttribute("href", "/politica-de-privacidade");
  });
});
