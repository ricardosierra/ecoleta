import { describe, expect, it } from "vitest";
import { contactSchema, tipoOperacaoOptions } from "@/lib/contact-schema";

/**
 * O schema é a validação de servidor do formulário de contato — o cliente usa o
 * mesmo objeto, mas quem decide é o handler. Um campo que afrouxa aqui afrouxa
 * na entrada da caixa de e-mail.
 */

const valido = {
  nome: "Maria Silva",
  email: "maria@empresa.com.br",
  telefone: "11 98888-7777",
  empresa: "Empresa Exemplo",
  tipoOperacao: "Indústria" as const,
  mensagem: "Gostaria de entender a coleta seletiva na nossa unidade fabril.",
};

describe("contactSchema", () => {
  it("aceita um envio completo", () => {
    const result = contactSchema.safeParse(valido);

    expect(result.success).toBe(true);
  });

  it("apara espaços das bordas antes de validar", () => {
    const result = contactSchema.safeParse({ ...valido, nome: "   Maria Silva   " });

    expect(result.success).toBe(true);
    expect(result.success && result.data.nome).toBe("Maria Silva");
  });

  it("recusa um nome que só tem espaço", () => {
    const result = contactSchema.safeParse({ ...valido, nome: "      " });

    expect(result.success).toBe(false);
    expect(result.success === false && result.error.issues[0].message).toBe("Informe seu nome.");
  });

  it.each([
    ["nome", "M", "Informe seu nome."],
    ["email", "maria(at)empresa.com", "E-mail inválido."],
    ["telefone", "1198", "Informe um telefone válido."],
    ["empresa", "E", "Informe a empresa."],
    ["mensagem", "curta", "Conte um pouco sobre sua operação (mínimo 10 caracteres)."],
  ])("recusa %s inválido com a mensagem da interface", (campo, valor, mensagem) => {
    const result = contactSchema.safeParse({ ...valido, [campo]: valor });

    expect(result.success).toBe(false);
    expect(result.success === false && result.error.issues[0].message).toBe(mensagem);
  });

  it("recusa um tipo de operação fora da lista", () => {
    const result = contactSchema.safeParse({ ...valido, tipoOperacao: "Startup" });

    expect(result.success).toBe(false);
    expect(result.success === false && result.error.issues[0].message).toBe(
      "Selecione o tipo de operação."
    );
  });

  it("aceita todos os tipos de operação oferecidos no formulário", () => {
    for (const tipo of tipoOperacaoOptions) {
      expect(contactSchema.safeParse({ ...valido, tipoOperacao: tipo }).success).toBe(true);
    }
  });

  it.each([
    ["nome", 121],
    ["email", 161],
    ["telefone", 41],
    ["empresa", 161],
    ["mensagem", 4001],
  ])("recusa %s acima do teto de tamanho", (campo, tamanho) => {
    const excedente =
      campo === "email" ? "a".repeat(tamanho - 12) + "@exemplo.com" : "a".repeat(tamanho);

    expect(contactSchema.safeParse({ ...valido, [campo]: excedente }).success).toBe(false);
  });

  it("deixa o honeypot passar — a rejeição é decisão do handler", () => {
    const result = contactSchema.safeParse({ ...valido, website: "http://spam.example" });

    expect(result.success).toBe(true);
    expect(result.success && result.data.website).toBe("http://spam.example");
  });

  it("não exige o honeypot: um envio humano nem manda o campo", () => {
    const { website, ...semHoneypot } = { ...valido, website: undefined };
    void website;

    expect(contactSchema.safeParse(semHoneypot).success).toBe(true);
  });
});
