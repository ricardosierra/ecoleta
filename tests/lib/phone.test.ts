import { describe, it, expect } from "vitest";
import { normalizePhone, normalizeWhatsApp, normalizePhoneNumber } from "@/lib/phone";

describe("normalizePhone", () => {
  it("normaliza número com DDI e 0 no DDD com 14 dígitos (Critério de Aceite)", () => {
    expect(normalizePhone("+55 (021) 99988-7766")).toBe("5521999887766");
    expect(normalizePhone("55021999887766")).toBe("5521999887766");
    expect(normalizePhone("+55 (011) 98765-4321")).toBe("5511987654321");
  });

  it("normaliza número celular com DDI sem 0 no DDD (13 dígitos)", () => {
    expect(normalizePhone("+55 (21) 99988-7766")).toBe("5521999887766");
    expect(normalizePhone("5521999887766")).toBe("5521999887766");
  });

  it("normaliza número fixo com DDI e 0 no DDD (13 dígitos)", () => {
    expect(normalizePhone("+55 (021) 3344-5566")).toBe("552133445566");
    expect(normalizePhone("5502133445566")).toBe("552133445566");
  });

  it("normaliza número fixo com DDI sem 0 no DDD (12 dígitos)", () => {
    expect(normalizePhone("+55 (21) 3344-5566")).toBe("552133445566");
    expect(normalizePhone("552133445566")).toBe("552133445566");
  });

  it("normaliza número celular local sem DDI (11 dígitos)", () => {
    expect(normalizePhone("(21) 99988-7766")).toBe("5521999887766");
    expect(normalizePhone("21999887766")).toBe("5521999887766");
  });

  it("normaliza número celular local com 0 no DDD sem DDI (12 dígitos)", () => {
    expect(normalizePhone("(021) 99988-7766")).toBe("5521999887766");
    expect(normalizePhone("021999887766")).toBe("5521999887766");
  });

  it("normaliza número fixo local sem DDI (10 dígitos)", () => {
    expect(normalizePhone("(21) 3344-5566")).toBe("552133445566");
    expect(normalizePhone("2133445566")).toBe("552133445566");
  });

  it("normaliza número fixo local com 0 no DDD sem DDI (11 dígitos)", () => {
    expect(normalizePhone("(021) 3344-5566")).toBe("552133445566");
    expect(normalizePhone("02133445566")).toBe("552133445566");
  });

  it("completa o DDD padrão quando o número vem só com 9 dígitos", () => {
    expect(normalizePhone("99988-7766")).toBe("5521999887766");
    expect(normalizePhone("999887766")).toBe("5521999887766");
  });

  it("completa o DDD padrão em número fixo de 8 dígitos", () => {
    expect(normalizePhone("3344-5566")).toBe("552133445566");
    expect(normalizePhone("33445566")).toBe("552133445566");
  });

  /**
   * DDD 55 (RS) é o caso em que "começa com 55" não quer dizer DDI. Quem decide
   * é o tamanho: 10 ou 11 dígitos é número local, mesmo começando com 55.
   */
  it("trata celular local do DDD 55 (RS) como número local, não como DDI", () => {
    expect(normalizePhone("(55) 99999-1234")).toBe("5555999991234");
    expect(normalizePhone("55999991234")).toBe("5555999991234");
  });

  it("trata fixo local do DDD 55 (RS) como número local, não como DDI", () => {
    expect(normalizePhone("(55) 3344-5566")).toBe("555533445566");
    expect(normalizePhone("5533445566")).toBe("555533445566");
  });

  it("mantém o número do DDD 55 que já vem com DDI (12 e 13 dígitos)", () => {
    expect(normalizePhone("+55 (55) 99999-1234")).toBe("5555999991234");
    expect(normalizePhone("5555999991234")).toBe("5555999991234");
    expect(normalizePhone("+55 (55) 3344-5566")).toBe("555533445566");
  });

  it("é idempotente: normalizar o que já foi normalizado não muda nada", () => {
    for (const entrada of ["(55) 99999-1234", "(55) 3344-5566", "(21) 99988-7766", "99988-7766", "3344-5566"]) {
      const uma = normalizePhone(entrada);
      expect(normalizePhone(uma)).toBe(uma);
    }
  });

  it("completa o DDD padrão em fixo de 8 dígitos que começa com 55", () => {
    expect(normalizePhone("5512-3456")).toBe("552155123456");
  });

  it("retorna string vazia para entradas vazias, nulas ou indefinidas", () => {
    expect(normalizePhone("")).toBe("");
    expect(normalizePhone(null)).toBe("");
    expect(normalizePhone(undefined)).toBe("");
    expect(normalizePhone("   ")).toBe("");
    expect(normalizePhone("abc-def")).toBe("");
  });

  it("os aliases normalizeWhatsApp e normalizePhoneNumber funcionam identicamente", () => {
    expect(normalizeWhatsApp("+55 (021) 99988-7766")).toBe("5521999887766");
    expect(normalizePhoneNumber("+55 (021) 99988-7766")).toBe("5521999887766");
  });
});
