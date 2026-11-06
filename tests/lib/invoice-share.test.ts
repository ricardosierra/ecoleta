import { describe, expect, it } from "vitest";
import { invoiceWhatsAppLink } from "@/lib/invoice-share";

describe("invoiceWhatsAppLink", () => {
  it("monta o wa.me com o número só em dígitos e o texto codificado", () => {
    const link = invoiceWhatsAppLink("(21) 99919-3898", "Olá, Cliente.\nPix: abc");

    expect(link).toBe(`https://wa.me/21999193898?text=${encodeURIComponent("Olá, Cliente.\nPix: abc")}`);
  });

  it("preserva acentos e quebras de linha quando o link é lido de volta", () => {
    const texto = "Sua fatura está em atraso.\n\nBoleto: https://example.com/i/1?x=1&y=2";
    const link = new URL(invoiceWhatsAppLink("5521999193898", texto));

    expect(link.searchParams.get("text")).toBe(texto);
  });

  it("sem número cadastrado abre o seletor de contatos com o texto pronto", () => {
    expect(invoiceWhatsAppLink(null, "Olá")).toBe(`https://wa.me/?text=${encodeURIComponent("Olá")}`);
    expect(invoiceWhatsAppLink("", "Olá")).toBe(`https://wa.me/?text=${encodeURIComponent("Olá")}`);
  });
});
