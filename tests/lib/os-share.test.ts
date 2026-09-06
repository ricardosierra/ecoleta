import { describe, expect, it } from "vitest";
import {
  formatOsDate,
  formatOsDateTime,
  osFieldValue,
  osNumber,
  osShareMessage,
  osWhatsAppLink,
  type ServiceOrder,
} from "@/lib/os-share";

const OS: ServiceOrder = {
  id: 42,
  client_name: "Heineken",
  client_email: "contato@heineken.exemplo",
  client_whatsapp: "5521999887766",
  weight: "150 kg",
  collection_date: "2026-09-03",
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

  it("devolve travessão para vazio, nulo e lixo", () => {
    expect(formatOsDate("")).toBe("—");
    expect(formatOsDate(null)).toBe("—");
    expect(formatOsDate(undefined)).toBe("—");
    expect(formatOsDate("0000-00-00")).toBe("—");
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

  it("troca vazio e nulo por travessão", () => {
    expect(osFieldValue("")).toBe("—");
    expect(osFieldValue("   ")).toBe("—");
    expect(osFieldValue(null)).toBe("—");
    expect(osFieldValue(undefined)).toBe("—");
  });
});

describe("osShareMessage", () => {
  it("leva os dados da coleta e o link", () => {
    const texto = osShareMessage(OS);

    expect(texto).toContain("*Ordem de Serviço Nº 00042*");
    expect(texto).toContain("Cliente: Heineken");
    expect(texto).toContain("Data da coleta: 03/09/2026");
    expect(texto).toContain("Pesagem: 150 kg");
    expect(texto).toContain("Qtd. contêineres: 2");
    expect(texto).toContain("Abrir e imprimir: https://ecolevaeco.com/api/os/view.php?id=42&t=abc");
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
