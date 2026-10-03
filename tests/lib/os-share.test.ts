import { describe, expect, it } from "vitest";
import {
  OS_SUPPORT_PHONE,
  formatOsDate,
  formatOsDateTime,
  osDocumentFields,
  osFieldValue,
  osNumber,
  osShareMessage,
  osWhatsAppLink,
  osWhatsAppStatusLabel,
  osWhatsAppStatusTone,
  type ServiceOrder,
} from "@/lib/os-share";

const OS: ServiceOrder = {
  id: 42,
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
  share_url: "https://ecolevaeco.com/api/os/view.php?id=42&t=abc",
};

describe("osNumber", () => {
  it("preenche com zeros até cinco dígitos", () => {
    expect(osNumber(42)).toBe("00042");
    expect(osNumber(123456)).toBe("123456");
  });
});

describe("formatOsDate", () => {
  it("converte a data ISO do banco para dd/mm/aaaa", () => {
    expect(formatOsDate("2026-09-03")).toBe("03/09/2026");
  });

  /**
   * A regressão que motivou o módulo: `new Date("2026-09-03")` é meia-noite UTC
   * e, relido em America/Sao_Paulo, voltava como 02/09/2026 — a coleta aparecia
   * um dia antes do que o operador digitou.
   */
  it("não desloca o dia por fuso horário", () => {
    expect(formatOsDate("2026-01-01")).toBe("01/01/2026");
    expect(formatOsDate("2026-12-31")).toBe("31/12/2026");
  });

  it("aceita o timestamp completo e ignora a hora", () => {
    expect(formatOsDate("2026-09-03 14:22:00")).toBe("03/09/2026");
  });

  /** O marcador de campo vazio é o hífen, o mesmo que `osFormatDate()` usa no PHP. */
  it("devolve o marcador de campo vazio para vazio, nulo e lixo", () => {
    expect(formatOsDate("")).toBe("-");
    expect(formatOsDate(null)).toBe("-");
    expect(formatOsDate(undefined)).toBe("-");
    expect(formatOsDate("0000-00-00")).toBe("-");
  });
});

describe("formatOsDateTime", () => {
  it("mostra data e hora do TIMESTAMP do MySQL", () => {
    expect(formatOsDateTime("2026-09-03 14:22:00")).toBe("03/09/2026 14:22");
  });

  it("aceita o separador ISO", () => {
    expect(formatOsDateTime("2026-09-03T14:22:00Z")).toBe("03/09/2026 14:22");
  });

  it("devolve travessão quando não há envio registrado", () => {
    expect(formatOsDateTime(null)).toBe("—");
  });
});

describe("osFieldValue", () => {
  it("mantém o valor preenchido, inclusive zero", () => {
    expect(osFieldValue("150 kg")).toBe("150 kg");
    expect(osFieldValue(0)).toBe("0");
  });

  it("troca vazio e nulo pelo marcador de campo vazio (hífen)", () => {
    expect(osFieldValue("")).toBe("-");
    expect(osFieldValue("   ")).toBe("-");
    expect(osFieldValue(null)).toBe("-");
    expect(osFieldValue(undefined)).toBe("-");
  });
});

/**
 * O documento que o cliente recebe sai do PHP (`osDocumentHtml()`, `osEmailText()`
 * e `osWhatsAppText()` em public/api/os/os_lib.php). Estas linhas são as mesmas
 * que tests/php/Unit/OsLibTest.php confere do outro lado: rótulo, ordem e
 * marcador de vazio idênticos. Mexeu aqui, mexa lá.
 */
const LINHAS_DA_OS = [
  "Cliente: Heineken",
  "Endereço da coleta: Av. das Américas, 500",
  "Data da coleta: 03/09/2026",
  "Horário aproximado: 14:30",
  "Material coletado: Óleo vegetal usado",
  "Pesagem: 150 kg",
  "Responsável pela coleta: Equipe A",
  "Qtd. sacos: 12",
  "Qtd. contêineres: 2",
];

const LINHAS_DA_OS_VAZIA = [
  "Cliente: Heineken",
  "Endereço da coleta: -",
  "Data da coleta: -",
  "Horário aproximado: -",
  "Material coletado: -",
  "Pesagem: -",
  "Responsável pela coleta: -",
  "Qtd. sacos: -",
  "Qtd. contêineres: -",
];

describe("osWhatsAppStatus", () => {
  /**
   * `whatsapp_sent_at` só diz que a Meta ACEITOU o pedido. O que a tela mostra
   * vem do status da última mensagem, que o webhook vai atualizando.
   */
  it("dá a cada status da Meta o seu rótulo, sem chamar tudo de enviada", () => {
    expect(osWhatsAppStatusLabel("accepted")).toBe("Aceita pela Meta");
    expect(osWhatsAppStatusLabel("sent")).toBe("Enviada ao WhatsApp");
    expect(osWhatsAppStatusLabel("delivered")).toBe("Entregue");
    expect(osWhatsAppStatusLabel("read")).toBe("Lida");
    expect(osWhatsAppStatusLabel("failed")).toBe("Falhou");
  });

  it("devolve null quando o robô nunca enviou a OS, e o texto cru para status que não conhece", () => {
    expect(osWhatsAppStatusLabel(null)).toBeNull();
    expect(osWhatsAppStatusLabel(undefined)).toBeNull();
    expect(osWhatsAppStatusLabel("")).toBeNull();
    expect(osWhatsAppStatusLabel("em_analise")).toBe("em_analise");
  });

  it("separa o que deu certo, o que ainda espera e o que falhou", () => {
    expect(osWhatsAppStatusTone("delivered")).toBe("ok");
    expect(osWhatsAppStatusTone("read")).toBe("ok");
    expect(osWhatsAppStatusTone("accepted")).toBe("pendente");
    expect(osWhatsAppStatusTone("sent")).toBe("pendente");
    expect(osWhatsAppStatusTone("failed")).toBe("erro");
    expect(osWhatsAppStatusTone(null)).toBe("pendente");
  });
});

describe("osDocumentFields", () => {
  it("segue a ordem e os rótulos do documento do PHP", () => {
    const linhas = osDocumentFields(OS).map(({ label, value }) => `${label}: ${value}`);

    expect(linhas).toEqual(LINHAS_DA_OS);
  });

  it("usa o hífen nos campos vazios, inclusive quantidade zero preservada", () => {
    const vazia: ServiceOrder = { id: 42, client_name: "Heineken" };
    expect(osDocumentFields(vazia).map(({ label, value }) => `${label}: ${value}`)).toEqual(LINHAS_DA_OS_VAZIA);

    const zero = osDocumentFields({ ...OS, bags_count: 0 }).find(campo => campo.label === "Qtd. sacos");
    expect(zero?.value).toBe("0");
  });
});

describe("osShareMessage", () => {
  it("leva os dados da coleta, na ordem do documento, e o link", () => {
    const [titulo, ...resto] = osShareMessage(OS).split("\n");

    expect(titulo).toContain("*Ordem de Serviço Nº 00042*");
    expect(resto).toEqual([
      "",
      ...LINHAS_DA_OS,
      "",
      "Abrir e imprimir: https://ecolevaeco.com/api/os/view.php?id=42&t=abc",
      "",
      "Caso precise, envie WhatsApp para (21) 99152-9383.",
    ]);
  });

  it("põe o responsável antes das quantidades, como o documento", () => {
    const texto = osShareMessage(OS);

    expect(texto.indexOf("Responsável pela coleta")).toBeLessThan(texto.indexOf("Qtd. sacos"));
    expect(texto.indexOf("Qtd. sacos")).toBeLessThan(texto.indexOf("Qtd. contêineres"));
  });

  it("marca os campos vazios com hífen, sem misturar com travessão", () => {
    const [, ...resto] = osShareMessage({ id: 42, client_name: "Heineken" }).split("\n");

    expect(resto.slice(1, 1 + LINHAS_DA_OS_VAZIA.length)).toEqual(LINHAS_DA_OS_VAZIA);
    // O único travessão da mensagem é o do título; nenhum campo o usa.
    expect(resto.join("\n")).not.toContain("—");
  });

  it("usa o telefone de suporte da constante", () => {
    expect(OS_SUPPORT_PHONE).toBe("(21) 99152-9383");
    expect(osShareMessage(OS)).toContain(`envie WhatsApp para ${OS_SUPPORT_PHONE}.`);
  });

  it("omite a linha do link quando a OS ainda não tem um", () => {
    const texto = osShareMessage({ ...OS, share_url: null });

    expect(texto).not.toContain("Abrir e imprimir");
    expect(texto).not.toContain("undefined");
    expect(texto).toContain("Cliente: Heineken");
  });
});

describe("osWhatsAppLink", () => {
  it("endereça o número do cliente com a mensagem pronta", () => {
    const link = osWhatsAppLink(OS);

    expect(link.startsWith("https://wa.me/5521999887766?text=")).toBe(true);
    expect(decodeURIComponent(link.split("?text=")[1])).toBe(osShareMessage(OS));
  });

  it("limpa a formatação do número cadastrado", () => {
    const link = osWhatsAppLink({ ...OS, client_whatsapp: "+55 (21) 99988-7766" });

    expect(link.startsWith("https://wa.me/5521999887766?")).toBe(true);
  });

  /** Sem número, o WhatsApp abre o seletor de contatos com o texto pronto. */
  it("cai no seletor de contatos quando o cliente não tem número", () => {
    expect(osWhatsAppLink({ ...OS, client_whatsapp: null }).startsWith("https://wa.me/?text=")).toBe(true);
    expect(osWhatsAppLink({ ...OS, client_whatsapp: "" }).startsWith("https://wa.me/?text=")).toBe(true);
  });
});
