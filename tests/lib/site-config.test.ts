import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

/**
 * lib/site.config.ts lê as variáveis NEXT_PUBLIC_* no momento em que o módulo é
 * carregado, então cada caso configura o ambiente e importa o módulo de novo.
 *
 * O que está em jogo: o CLAUDE.md manda copiar o .env.example, e um valor de
 * exemplo malformado (55XXXXXXXXXXX, 00.000.000/0000-00) não pode vencer o
 * padrão bom do código. Com o número de exemplo o link do WhatsApp virava
 * https://wa.me/55 e o botão flutuante abria uma conversa com ninguém.
 */

const PADRAO = {
  whatsapp: "5521991529383",
  whatsappDisplay: "(21) 99152-9383",
  cnpj: "57.772.812/0001-72",
  instagram: "https://instagram.com/ecoleva.eco",
  linkedin: "https://linkedin.com/company/econformidade",
  url: "https://www.ecolevaeco.com",
  email: "diretoria@econformidade.com.br",
};

async function carrega(env: Record<string, string> = {}) {
  vi.resetModules();
  for (const [nome, valor] of Object.entries(env)) {
    vi.stubEnv(nome, valor);
  }

  return import("@/lib/site.config");
}

beforeEach(() => {
  for (const nome of [
    "NEXT_PUBLIC_SITE_URL",
    "NEXT_PUBLIC_CONTACT_EMAIL",
    "NEXT_PUBLIC_WHATSAPP_NUMBER",
    "NEXT_PUBLIC_INSTAGRAM_URL",
    "NEXT_PUBLIC_LINKEDIN_URL",
    "NEXT_PUBLIC_CNPJ",
    "NEXT_PUBLIC_ADDRESS",
    "NEXT_PUBLIC_PHONE_COMERCIAL",
  ]) {
    vi.stubEnv(nome, "");
  }
});

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("site.config: padrões", () => {
  it("com o ambiente vazio usa os padrões do código", async () => {
    const { siteConfig, whatsappLink } = await carrega();

    expect(siteConfig.contact.whatsappNumber).toBe(PADRAO.whatsapp);
    expect(siteConfig.contact.whatsappDisplay).toBe(PADRAO.whatsappDisplay);
    expect(siteConfig.company.cnpj).toBe(PADRAO.cnpj);
    expect(siteConfig.social.instagram).toBe(PADRAO.instagram);
    expect(siteConfig.social.linkedin).toBe(PADRAO.linkedin);
    expect(siteConfig.url).toBe(PADRAO.url);
    expect(siteConfig.contact.email).toBe(PADRAO.email);
    expect(whatsappLink).toMatch(/^https:\/\/wa\.me\/5521991529383\?text=/);
  });

  it("valor só com espaços conta como ausente", async () => {
    const { siteConfig } = await carrega({
      NEXT_PUBLIC_WHATSAPP_NUMBER: "   ",
      NEXT_PUBLIC_CNPJ: "  ",
    });

    expect(siteConfig.contact.whatsappNumber).toBe(PADRAO.whatsapp);
    expect(siteConfig.company.cnpj).toBe(PADRAO.cnpj);
  });
});

describe("site.config: WhatsApp", () => {
  it("o número de exemplo do .env.example cai no padrão em vez de virar wa.me/55", async () => {
    const { siteConfig, whatsappLink } = await carrega({ NEXT_PUBLIC_WHATSAPP_NUMBER: "55XXXXXXXXXXX" });

    expect(siteConfig.contact.whatsappNumber).toBe(PADRAO.whatsapp);
    expect(whatsappLink).toMatch(/^https:\/\/wa\.me\/5521991529383\?/);
  });

  it.each(["abc", "5511", "123", "55119999988889999999", "00000000000000", "55 11 9999-ABCD"])(
    "recusa %j e cai no padrão",
    async (valor) => {
      const { siteConfig } = await carrega({ NEXT_PUBLIC_WHATSAPP_NUMBER: valor });

      expect(siteConfig.contact.whatsappNumber).toBe(PADRAO.whatsapp);
    }
  );

  it.each([
    ["5511999998888", "5511999998888", "(11) 99999-8888"],
    ["+55 (11) 99999-8888", "5511999998888", "(11) 99999-8888"],
    ["55 11 9999-8888", "551199998888", "(11) 9999-8888"],
  ])("aceita %j e normaliza para dígitos", async (entrada, esperado, exibido) => {
    const { siteConfig, whatsappLink } = await carrega({ NEXT_PUBLIC_WHATSAPP_NUMBER: entrada });

    expect(siteConfig.contact.whatsappNumber).toBe(esperado);
    expect(siteConfig.contact.whatsappDisplay).toBe(exibido);
    expect(whatsappLink).toContain(`https://wa.me/${esperado}?text=`);
  });

  it("o telefone comercial acompanha o WhatsApp quando não há variável própria", async () => {
    const { siteConfig } = await carrega({ NEXT_PUBLIC_WHATSAPP_NUMBER: "5511999998888" });

    expect(siteConfig.company.phoneComercial).toBe("(11) 99999-8888");
  });

  it("o telefone comercial próprio, quando válido, vence", async () => {
    const { siteConfig } = await carrega({
      NEXT_PUBLIC_WHATSAPP_NUMBER: "5511999998888",
      NEXT_PUBLIC_PHONE_COMERCIAL: "(21) 3333-4444",
    });

    expect(siteConfig.company.phoneComercial).toBe("(21) 3333-4444");
  });

  it("telefone comercial malformado cai no derivado do WhatsApp", async () => {
    const { siteConfig } = await carrega({ NEXT_PUBLIC_PHONE_COMERCIAL: "(21) XXXX-XXXX" });

    expect(siteConfig.company.phoneComercial).toBe(PADRAO.whatsappDisplay);
  });
});

describe("site.config: CNPJ", () => {
  it("o CNPJ de exemplo do .env.example cai no padrão", async () => {
    const { siteConfig } = await carrega({ NEXT_PUBLIC_CNPJ: "00.000.000/0000-00" });

    expect(siteConfig.company.cnpj).toBe(PADRAO.cnpj);
  });

  it.each(["123", "12345678000195X", "11.111.111/1111-11", "00000000000000", "abc"])(
    "recusa %j e cai no padrão",
    async (valor) => {
      const { siteConfig } = await carrega({ NEXT_PUBLIC_CNPJ: valor });

      expect(siteConfig.company.cnpj).toBe(PADRAO.cnpj);
    }
  );

  it.each([
    ["12.345.678/0001-95", "12.345.678/0001-95"],
    ["12345678000195", "12.345.678/0001-95"],
  ])("aceita %j", async (entrada, esperado) => {
    const { siteConfig } = await carrega({ NEXT_PUBLIC_CNPJ: entrada });

    expect(siteConfig.company.cnpj).toBe(esperado);
  });
});

describe("site.config: URLs e e-mail", () => {
  it("o Instagram de exemplo do .env.example cai no padrão", async () => {
    const { siteConfig } = await carrega({ NEXT_PUBLIC_INSTAGRAM_URL: "https://instagram.com/XXXXXXXX" });

    expect(siteConfig.social.instagram).toBe(PADRAO.instagram);
  });

  it("o LinkedIn de exemplo do .env.example cai no padrão", async () => {
    const { siteConfig } = await carrega({ NEXT_PUBLIC_LINKEDIN_URL: "https://linkedin.com/company/XXXXXXXX" });

    expect(siteConfig.social.linkedin).toBe(PADRAO.linkedin);
  });

  it.each(["javascript:alert(1)", "instagram.com/ecoleva", "não é url"])(
    "link social %j não vira href: cai no padrão",
    async (valor) => {
      const { siteConfig } = await carrega({ NEXT_PUBLIC_INSTAGRAM_URL: valor });

      expect(siteConfig.social.instagram).toBe(PADRAO.instagram);
    }
  );

  it("aceita links sociais reais", async () => {
    const { siteConfig } = await carrega({
      NEXT_PUBLIC_INSTAGRAM_URL: "https://instagram.com/ecolevaeco",
      NEXT_PUBLIC_LINKEDIN_URL: "https://www.linkedin.com/company/ecoleva",
    });

    expect(siteConfig.social.instagram).toBe("https://instagram.com/ecolevaeco");
    expect(siteConfig.social.linkedin).toBe("https://www.linkedin.com/company/ecoleva");
  });

  it("URL do site sem protocolo não derruba o build do metadataBase: cai no padrão", async () => {
    const { siteConfig } = await carrega({ NEXT_PUBLIC_SITE_URL: "ecolevaeco.com" });

    expect(siteConfig.url).toBe(PADRAO.url);
    expect(() => new URL(siteConfig.url)).not.toThrow();
  });

  it("URL do site válida é respeitada, com ou sem barra final", async () => {
    const { siteConfig } = await carrega({ NEXT_PUBLIC_SITE_URL: "https://ecolevaeco.com/" });

    expect(siteConfig.url).toBe("https://ecolevaeco.com/");
  });

  it("e-mail de contato malformado cai no padrão", async () => {
    const { siteConfig } = await carrega({ NEXT_PUBLIC_CONTACT_EMAIL: "contato(at)ecolevaeco" });

    expect(siteConfig.contact.email).toBe(PADRAO.email);
  });

  it("e-mail de contato válido é respeitado", async () => {
    const { siteConfig } = await carrega({ NEXT_PUBLIC_CONTACT_EMAIL: "contato@ecolevaeco.com" });

    expect(siteConfig.contact.email).toBe("contato@ecolevaeco.com");
  });
});

describe("site.config: política de privacidade", () => {
  it("a data de atualização é uma constante, não o relógio do build", async () => {
    const { siteConfig } = await carrega();

    expect(siteConfig.legal.privacyPolicyUpdatedAt).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(Number.isNaN(Date.parse(siteConfig.legal.privacyPolicyUpdatedAt))).toBe(false);
  });
});
