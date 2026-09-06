import { describe, expect, it } from "vitest";
import {
  deliveryMark,
  formatConversationStamp,
  formatDayLabel,
  formatPhone,
  groupMessagesByDay,
  humanMinutes,
  initials,
  windowLabel,
  windowTone,
  windowTooltip,
  type WhatsAppMessage,
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
