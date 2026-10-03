import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

/**
 * O CLAUDE.md manda copiar o .env.example para .env.local. Uma linha com valor
 * de exemplo (55XXXXXXXXXXX, 00.000.000/0000-00) passa a SOBRESCREVER o padrão
 * bom do código, e com o número de exemplo o link do WhatsApp virava wa.me/55.
 * Estas variáveis têm de vir vazias, com o formato esperado só em comentário.
 */

const exemplo = readFileSync(path.join(process.cwd(), ".env.example"), "utf-8");

function valorDe(nome: string): string | undefined {
  const linha = exemplo.split("\n").find((l) => l.startsWith(`${nome}=`));

  return linha === undefined ? undefined : linha.slice(nome.length + 1).trim();
}

describe(".env.example", () => {
  it.each([
    "NEXT_PUBLIC_SITE_URL",
    "NEXT_PUBLIC_WHATSAPP_NUMBER",
    "NEXT_PUBLIC_INSTAGRAM_URL",
    "NEXT_PUBLIC_LINKEDIN_URL",
    "NEXT_PUBLIC_CNPJ",
    "NEXT_PUBLIC_ADDRESS",
  ])("%s vem vazia para não sobrescrever o padrão do código", (nome) => {
    expect(valorDe(nome), `${nome} sumiu do arquivo`).toBeDefined();
    expect(valorDe(nome)).toBe("");
  });

  it("nenhuma variável pública traz valor de exemplo com XXXX ou CNPJ zerado", () => {
    const publicas = exemplo
      .split("\n")
      .filter((l) => l.startsWith("NEXT_PUBLIC_") && !l.startsWith("#"));

    for (const linha of publicas) {
      expect(linha, linha).not.toMatch(/X{4,}/);
      expect(linha, linha).not.toMatch(/00\.000\.000\/0000-00/);
    }
  });

  it("documenta o formato esperado de cada variável em comentário", () => {
    expect(exemplo).toMatch(/#.*5511999999999/);
    expect(exemplo).toMatch(/#.*00\.000\.000\/0000-00/);
  });
});
