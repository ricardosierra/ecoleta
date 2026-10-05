import { describe, expect, it } from "vitest";
import {
  deliveryMark,
  formatConversationStamp,
  formatDayLabel,
  formatPhone,
  groupMessagesByDay,
  humanMinutes,
  initialTemplateValues,
  initials,
  looksLikePhoneSearch,
  matchesConversationSearch,
  missingTemplateParams,
  recorderMimeType,
  templateSendability,
  templateStatusLabel,
  windowLabel,
  windowTone,
  windowTooltip,
  type WhatsAppConversation,
  type WhatsAppMessage,
  type WhatsAppTemplate,
  type WhatsAppWindow,
} from "@/lib/whatsapp";

const aberta = (minutos: number): WhatsAppWindow => ({
  open: true,
  expires_at: "2026-09-04T15:22:00Z",
  minutes_left: minutos,
});

const fechada: WhatsAppWindow = {
  open: false,
  expires_at: "2026-09-02T15:22:00Z",
  minutes_left: 0,
};

describe("windowTone", () => {
  it("verde enquanto sobra tempo", () => {
    expect(windowTone(aberta(600))).toBe("open");
  });

  it("âmbar nas últimas duas horas", () => {
    expect(windowTone(aberta(120))).toBe("soon");
    expect(windowTone(aberta(15))).toBe("soon");
  });

  it("vermelho quando fechada ou desconhecida", () => {
    expect(windowTone(fechada)).toBe("closed");
    expect(windowTone(null)).toBe("closed");
    expect(windowTone(undefined)).toBe("closed");
  });
});

describe("humanMinutes", () => {
  it("escala de minutos até dias", () => {
    expect(humanMinutes(38)).toBe("38 min");
    expect(humanMinutes(60)).toBe("1 h");
    expect(humanMinutes(150)).toBe("2 h 30 min");
    expect(humanMinutes(1440)).toBe("1 d");
    expect(humanMinutes(1620)).toBe("1 d 3 h");
  });

  it("sem tempo restante é expirada", () => {
    expect(humanMinutes(0)).toBe("expirada");
    expect(humanMinutes(-5)).toBe("expirada");
    expect(humanMinutes(null)).toBe("expirada");
  });
});

describe("windowTooltip", () => {
  /**
   * É o texto que o usuário pediu explicitamente: dentro da janela o envio não
   * é cobrado, e o tooltip precisa dizer isso.
   */
  it("dentro da janela avisa que não vai cobrar", () => {
    const texto = windowTooltip(aberta(180));

    expect(texto).toContain("Dentro da janela de 24 horas");
    expect(texto).toContain("3 h");
    expect(texto).toContain("gratuito");
    expect(texto).toContain("não cobra");
  });

  it("fora da janela avisa que exige template e é cobrado", () => {
    expect(windowTooltip(fechada)).toContain("Fora da janela de 24 horas");
    expect(windowTooltip(fechada)).toContain("cobrado");
  });

  it("cliente que nunca escreveu também está fora da janela", () => {
    expect(windowTooltip(null)).toContain("nunca escreveu");
    expect(windowTooltip(null)).toContain("cobrado");
  });
});

describe("windowLabel", () => {
  it("resume o estado da faixa no topo da conversa", () => {
    expect(windowLabel(aberta(90))).toBe("Janela aberta — fecha em 1 h 30 min");
    expect(windowLabel(fechada)).toBe("Janela fechada — só por template");
    expect(windowLabel(null)).toBe("Janela fechada — só por template");
  });
});

describe("formatDayLabel", () => {
  const hoje = new Date(2026, 8, 3, 12, 0, 0);

  it("nomeia hoje e ontem", () => {
    expect(formatDayLabel(new Date(2026, 8, 3, 9, 0, 0).toISOString(), hoje)).toBe("Hoje");
    expect(formatDayLabel(new Date(2026, 8, 2, 23, 0, 0).toISOString(), hoje)).toBe("Ontem");
  });

  /**
   * "Ontem" é dia de calendário, não 24 horas atrás: uma mensagem das 23h de
   * ontem, lida às 0h30 de hoje, tem 1h30 de vida e ainda é de ontem.
   */
  it("conta dia de calendário, não blocos de 24 horas", () => {
    const madrugada = new Date(2026, 8, 3, 0, 30, 0);
    expect(formatDayLabel(new Date(2026, 8, 2, 23, 0, 0).toISOString(), madrugada)).toBe("Ontem");
  });

  it("data cheia para o resto", () => {
    expect(formatDayLabel(new Date(2026, 7, 20, 10, 0, 0).toISOString(), hoje)).toBe("20/08/2026");
  });

  it("vazio para instante ausente", () => {
    expect(formatDayLabel(null, hoje)).toBe("");
    expect(formatDayLabel("não é data", hoje)).toBe("");
  });
});

describe("formatConversationStamp", () => {
  const hoje = new Date(2026, 8, 3, 12, 0, 0);

  it("hora para hoje, Ontem, e dia/mês antes disso", () => {
    expect(formatConversationStamp(new Date(2026, 8, 3, 9, 5, 0).toISOString(), hoje)).toBe("09:05");
    expect(formatConversationStamp(new Date(2026, 8, 2, 9, 5, 0).toISOString(), hoje)).toBe("Ontem");
    expect(formatConversationStamp(new Date(2026, 7, 20, 9, 5, 0).toISOString(), hoje)).toBe("20/08");
  });
});

describe("groupMessagesByDay", () => {
  const mensagem = (id: number, iso: string): WhatsAppMessage => ({
    id,
    direction: "incoming",
    message_at: iso,
    body: `msg ${id}`,
  });

  it("agrupa mantendo a ordem e sem misturar dias", () => {
    const hoje = new Date(2026, 8, 3, 12, 0, 0);
    const grupos = groupMessagesByDay(
      [
        mensagem(1, new Date(2026, 8, 2, 10, 0, 0).toISOString()),
        mensagem(2, new Date(2026, 8, 2, 11, 0, 0).toISOString()),
        mensagem(3, new Date(2026, 8, 3, 9, 0, 0).toISOString()),
      ],
      hoje
    );

    expect(grupos).toHaveLength(2);
    expect(grupos[0].day).toBe("Ontem");
    expect(grupos[0].messages.map(m => m.id)).toEqual([1, 2]);
    expect(grupos[1].day).toBe("Hoje");
    expect(grupos[1].messages.map(m => m.id)).toEqual([3]);
  });

  it("lista vazia não gera grupo", () => {
    expect(groupMessagesByDay([])).toEqual([]);
  });
});

describe("initials", () => {
  it("primeira e última inicial do nome", () => {
    expect(initials("João da Heineken", "5521999887766")).toBe("JH");
    expect(initials("Heineken", null)).toBe("H");
  });

  it("cai nos últimos dígitos quando não há nome", () => {
    expect(initials(null, "5521999887766")).toBe("66");
    expect(initials("   ", "5521999887766")).toBe("66");
  });

  it("interrogação quando não há nada", () => {
    expect(initials(null, null)).toBe("?");
  });
});

describe("formatPhone", () => {
  it("formata celular e fixo brasileiros", () => {
    expect(formatPhone("5521999887766")).toBe("+55 (21) 99988-7766");
    expect(formatPhone("552133334444")).toBe("+55 (21) 3333-4444");
  });

  it("número de fora fica como veio, com o mais", () => {
    expect(formatPhone("14155552671")).toBe("+14155552671");
  });

  it("vazio continua vazio", () => {
    expect(formatPhone(null)).toBe("");
    expect(formatPhone("")).toBe("");
  });
});

describe("deliveryMark", () => {
  it("usa o vocabulário de tiques do WhatsApp", () => {
    expect(deliveryMark("sent")).toBe("✓");
    expect(deliveryMark("delivered")).toBe("✓✓");
    expect(deliveryMark("read")).toBe("✓✓");
    expect(deliveryMark("failed")).toBe("!");
    expect(deliveryMark("accepted")).toBe("·");
    expect(deliveryMark(null)).toBe("·");
  });
});

const conversaDaHeineken: Pick<WhatsAppConversation, "name"> = { name: "Heineken" };

const templateOs: WhatsAppTemplate = {
  name: "ordem_servico",
  language: "pt_BR",
  status: "APPROVED",
  body_text: "Olá {{1}}, sua OS {{2}} está em {{3}}",
  params_count: 3,
  param_labels: ["Nome do Cliente", "Número da OS", "Link da OS"],
  param_kinds: ["client_name", "os_number", "os_link"],
};

const templateFatura: WhatsAppTemplate = {
  name: "fatura_mensal",
  language: "pt_BR",
  status: "APPROVED",
  body_text: "Olá {{1}}, fatura de R$ {{2}} vence em {{3}}: {{4}}",
  params_count: 4,
  param_labels: ["Nome do Cliente", "Valor (R$)", "Vencimento", "Link da Fatura"],
  param_kinds: ["client_name", "amount", "due_date", "invoice_link"],
};

describe("initialTemplateValues", () => {
  it("pré-preenche só o nome do cliente, no parâmetro que é o nome", () => {
    expect(initialTemplateValues(templateOs, conversaDaHeineken)).toEqual({ "1": "Heineken" });
  });

  it("não inventa número de OS, valor, vencimento nem link", () => {
    const valores = initialTemplateValues(templateFatura, conversaDaHeineken);

    expect(valores).toEqual({ "1": "Heineken" });
    expect(valores["2"]).toBeUndefined();
    expect(valores["3"]).toBeUndefined();
    expect(valores["4"]).toBeUndefined();
  });

  it("acha o nome pelo rótulo quando o servidor não manda os tipos", () => {
    const semTipos: WhatsAppTemplate = { ...templateOs, param_kinds: undefined };

    expect(initialTemplateValues(semTipos, conversaDaHeineken)).toEqual({ "1": "Heineken" });
  });

  it("template desconhecido (sem rótulos) não ganha nada, nem no parâmetro 1", () => {
    const desconhecido: WhatsAppTemplate = {
      name: "promocao",
      language: "pt_BR",
      status: "APPROVED",
      body_text: "Oi {{1}}, veja {{2}}",
      params_count: 2,
    };

    expect(initialTemplateValues(desconhecido, conversaDaHeineken)).toEqual({});
  });

  it("conversa sem nome deixa o parâmetro em branco, sem inventar um", () => {
    expect(initialTemplateValues(templateOs, { name: "  " })).toEqual({});
  });

  it("sem template ou sem conversa devolve vazio", () => {
    expect(initialTemplateValues(null, conversaDaHeineken)).toEqual({});
    expect(initialTemplateValues(templateOs, null)).toEqual({});
  });
});

describe("missingTemplateParams", () => {
  it("lista as variáveis em branco, contando espaços como branco", () => {
    expect(missingTemplateParams(templateFatura, { "1": "Heineken", "2": "  ", "4": "https://x" })).toEqual([2, 3]);
  });

  it("vazio quando tudo foi preenchido", () => {
    expect(
      missingTemplateParams(templateOs, { "1": "Heineken", "2": "00042", "3": "https://x" })
    ).toEqual([]);
  });

  it("template sem variáveis não exige nada", () => {
    expect(missingTemplateParams({ ...templateOs, params_count: 0 }, {})).toEqual([]);
  });
});

describe("templateSendability e templateStatusLabel", () => {
  it("só o aprovado envia sem ressalva", () => {
    expect(templateSendability({ status: "APPROVED" })).toBe("ok");
  });

  it("o não verificado envia, com aviso", () => {
    expect(templateSendability({ status: "UNVERIFIED" })).toBe("unverified");
  });

  it.each(["PENDING", "REJECTED", "PAUSED", "DISABLED", "IN_APPEAL", "UNKNOWN", "", undefined])(
    "%s é bloqueado",
    status => {
      expect(templateSendability({ status })).toBe("blocked");
    }
  );

  it("traduz o status para a tela", () => {
    expect(templateStatusLabel("APPROVED")).toBe("Aprovado");
    expect(templateStatusLabel("PENDING")).toBe("Em análise na Meta");
    expect(templateStatusLabel("REJECTED")).toBe("Rejeitado pela Meta");
    expect(templateStatusLabel("PAUSED")).toBe("Pausado pela Meta");
    expect(templateStatusLabel("UNVERIFIED")).toBe("Não verificado na Meta");
    expect(templateStatusLabel("ALGO_NOVO")).toBe("ALGO_NOVO");
    expect(templateStatusLabel(undefined)).toBe("Situação desconhecida");
  });
});

describe("looksLikePhoneSearch", () => {
  it("número com ou sem máscara é busca de telefone", () => {
    expect(looksLikePhoneSearch("21999887766")).toBe(true);
    expect(looksLikePhoneSearch("(21) 99988-7766")).toBe(true);
    expect(looksLikePhoneSearch("+55 21 99988 7766")).toBe(true);
  });

  it("nome com número ou texto não é", () => {
    expect(looksLikePhoneSearch("Posto 3")).toBe(false);
    expect(looksLikePhoneSearch("heineken")).toBe(false);
    expect(looksLikePhoneSearch("")).toBe(false);
    expect(looksLikePhoneSearch("--")).toBe(false);
  });
});

describe("recorderMimeType", () => {
  it("prefere ogg com opus quando o navegador grava (Firefox)", () => {
    const suporta = (tipo: string) => ["audio/ogg;codecs=opus", "audio/webm;codecs=opus"].includes(tipo);

    expect(recorderMimeType(suporta)).toBe("audio/ogg;codecs=opus");
  });

  it("no Chrome cai em mp4 (AAC), que a Meta aceita, e não em webm", () => {
    const suporta = (tipo: string) => ["audio/mp4", "audio/webm;codecs=opus", "audio/webm"].includes(tipo);

    expect(recorderMimeType(suporta)).toBe("audio/mp4");
  });

  it("só webm: devolve nada, porque a Meta recusa", () => {
    const suporta = (tipo: string) => tipo.startsWith("audio/webm");

    expect(recorderMimeType(suporta)).toBeUndefined();
  });
});

describe("matchesConversationSearch", () => {
  const padaria = {
    name: "Padaria",
    profile_name: "Maria da Padaria",
    client_name: null,
    phone: "5521933333333",
    last_message_preview: "Bom dia, pode passar hoje?",
  };
  const posto = {
    name: "Posto 3",
    profile_name: null,
    client_name: "Posto 3",
    phone: "5521988887777",
    last_message_preview: "oi",
  };

  it("termo vazio casa com tudo", () => {
    expect(matchesConversationSearch(padaria, "")).toBe(true);
    expect(matchesConversationSearch(padaria, "   ")).toBe(true);
  });

  it("acha por nome, perfil do WhatsApp e prévia, sem ligar para caixa e acento", () => {
    expect(matchesConversationSearch(padaria, "padaria")).toBe(true);
    expect(matchesConversationSearch(padaria, "MARIA")).toBe(true);
    expect(matchesConversationSearch(padaria, "pode passar")).toBe(true);
    expect(matchesConversationSearch({ ...padaria, name: "João" }, "joao")).toBe(true);
    expect(matchesConversationSearch({ ...padaria, name: "Joao" }, "João")).toBe(true);
  });

  it("nome com dígito ('Posto 3') não casa com telefone que contém o dígito", () => {
    expect(matchesConversationSearch(posto, "Posto 3")).toBe(true);
    // A padaria tem vários 3 no telefone, mas "Posto 3" não é número.
    expect(matchesConversationSearch(padaria, "Posto 3")).toBe(false);
  });

  it("busca só de número casa pelo telefone, com ou sem máscara", () => {
    expect(matchesConversationSearch(posto, "21988887777")).toBe(true);
    expect(matchesConversationSearch(posto, "(21) 98888-7777")).toBe(true);
    expect(matchesConversationSearch(posto, "88887")).toBe(true);
    expect(matchesConversationSearch(padaria, "(21) 98888-7777")).toBe(false);
  });
});
