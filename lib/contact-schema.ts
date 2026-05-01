import { z } from "zod";

export const tipoOperacaoOptions = [
  "Empresa",
  "Indústria",
  "Evento",
  "Condomínio",
  "Obra",
  "Outro",
] as const;

export const contactSchema = z.object({
  nome: z.string().trim().min(2, "Informe seu nome.").max(120),
  email: z.string().trim().email("E-mail inválido.").max(160),
  telefone: z
    .string()
    .trim()
    .min(8, "Informe um telefone válido.")
    .max(40),
  empresa: z.string().trim().min(2, "Informe a empresa.").max(160),
  tipoOperacao: z.enum(tipoOperacaoOptions, {
    message: "Selecione o tipo de operação.",
  }),
  mensagem: z
    .string()
    .trim()
    .min(10, "Conte um pouco sobre sua operação (mínimo 10 caracteres).")
    .max(4000),
  // Honeypot: aceita qualquer string; rejeição acontece no handler.
  website: z.string().optional(),
});

export type ContactInput = z.infer<typeof contactSchema>;
