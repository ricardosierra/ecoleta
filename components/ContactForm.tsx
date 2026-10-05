"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import Button from "@/components/Button";
import { ArrowRightIcon } from "@/components/icons";
import { tipoOperacaoOptions } from "@/lib/contact-schema";

type Status =
  | { kind: "idle" }
  | { kind: "submitting" }
  | { kind: "success" }
  | { kind: "error"; message: string };

// Quanto o envio pode esperar o servidor. Sem isto, uma hospedagem lenta deixa o
// botão em "Enviando…" para sempre e a pessoa sem saber se a mensagem saiu.
const SUBMIT_TIMEOUT_MS = 15_000;

const GENERIC_ERROR =
  "Não foi possível enviar sua mensagem agora. Tente novamente ou fale pelo WhatsApp.";

const TIMEOUT_ERROR =
  "O envio demorou mais do que o esperado. Verifique sua conexão e tente de novo, ou fale pelo WhatsApp.";

// Ordem dos campos na tela: é por ela que o foco vai ao primeiro inválido.
const FIELD_ORDER = [
  "nome",
  "email",
  "telefone",
  "empresa",
  "tipoOperacao",
  "mensagem",
] as const;

const initialState = {
  nome: "",
  email: "",
  telefone: "",
  empresa: "",
  tipoOperacao: "",
  mensagem: "",
  website: "", // honeypot
};

export default function ContactForm() {
  const [values, setValues] = useState(initialState);
  const [status, setStatus] = useState<Status>({ kind: "idle" });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const formRef = useRef<HTMLFormElement>(null);
  const successRef = useRef<HTMLDivElement>(null);
  // Pedido de foco para um campo: objeto novo a cada pedido, para o efeito
  // rodar de novo mesmo quando o campo é o mesmo da vez anterior.
  const [focusRequest, setFocusRequest] = useState<{ id: string } | null>(null);

  // O foco só vai depois do render: é nele que o aria-describedby do campo
  // passa a apontar para a mensagem, e o leitor de tela lê as duas juntas.
  useEffect(() => {
    if (!focusRequest) return;
    formRef.current?.querySelector<HTMLElement>(`#${focusRequest.id}`)?.focus();
  }, [focusRequest]);

  // Ao enviar, o formulário sai do DOM e leva o foco junto. Sem este efeito,
  // quem usa teclado ou leitor de tela cairia no <body> sem saber que deu certo.
  useEffect(() => {
    if (status.kind === "success") successRef.current?.focus();
  }, [status.kind]);

  /** Marca os erros e leva o foco ao primeiro campo inválido, na ordem da tela. */
  function showErrors(next: Record<string, string>) {
    setErrors(next);
    const first = FIELD_ORDER.find((id) => next[id]);
    if (first) setFocusRequest({ id: first });
  }

  function update<K extends keyof typeof initialState>(
    key: K,
    value: (typeof initialState)[K]
  ) {
    setValues((v) => ({ ...v, [key]: value }));
  }

  async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setErrors({});
    setStatus({ kind: "submitting" });

    // Validação simples no frontend (o backend é a fonte de verdade)
    const localErrors: Record<string, string> = {};
    if (values.nome.trim().length < 2) localErrors.nome = "Informe seu nome.";
    if (!/^.+@.+\..+$/.test(values.email)) localErrors.email = "E-mail inválido.";
    if (values.telefone.trim().length < 8)
      localErrors.telefone = "Informe um telefone válido.";
    if (values.empresa.trim().length < 2)
      localErrors.empresa = "Informe a empresa.";
    if (!values.tipoOperacao)
      localErrors.tipoOperacao = "Selecione o tipo de operação.";
    if (values.mensagem.trim().length < 10)
      localErrors.mensagem = "Conte um pouco sobre sua operação.";

    if (Object.keys(localErrors).length > 0) {
      showErrors(localErrors);
      setStatus({ kind: "idle" });
      return;
    }

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), SUBMIT_TIMEOUT_MS);

    try {
      const res = await fetch("/contact.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(values),
        signal: controller.signal,
      });

      if (res.ok) {
        setStatus({ kind: "success" });
        setValues(initialState);
        return;
      }

      const data = await res.json().catch(() => ({}));
      if (data.issues && Array.isArray(data.issues)) {
        const fieldErrors: Record<string, string> = {};
        for (const issue of data.issues) {
          if (issue.path) fieldErrors[issue.path] = issue.message;
        }
        showErrors(fieldErrors);
      }
      setStatus({ kind: "error", message: data.error || GENERIC_ERROR });
    } catch {
      setStatus({
        kind: "error",
        message: controller.signal.aborted ? TIMEOUT_ERROR : GENERIC_ERROR,
      });
    } finally {
      // Só aqui, depois de ler o corpo: o abort também cobre uma resposta que
      // chegou os cabeçalhos e parou no meio.
      clearTimeout(timer);
    }
  }

  if (status.kind === "success") {
    return (
      <div
        ref={successRef}
        role="status"
        tabIndex={-1}
        className="rounded-[10px] bg-(--color-bg-light) border border-(--color-accent) p-8 text-center"
      >
        <div className="size-12 rounded-full bg-(--color-accent) text-(--color-bg-dark) inline-flex items-center justify-center mb-4">
          <svg
            width={22}
            height={22}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={2.5}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden
          >
            <path d="m4.5 12.5 5 5 10-11" />
          </svg>
        </div>
        <h3 className="text-xl font-bold text-(--color-bg-dark)">
          Mensagem enviada com sucesso.
        </h3>
        <p className="mt-2 text-(--color-text-muted)">
          Em breve a equipe Ecoleva entrará em contato.
        </p>
        <button
          type="button"
          onClick={() => {
            setStatus({ kind: "idle" });
            // O botão some junto com o painel de sucesso: o foco volta ao
            // começo do formulário em vez de se perder.
            setFocusRequest({ id: FIELD_ORDER[0] });
          }}
          className="mt-6 text-sm font-semibold text-(--color-secondary) hover:underline"
        >
          Enviar outra mensagem
        </button>
      </div>
    );
  }

  const submitting = status.kind === "submitting";

  return (
    <form
      ref={formRef}
      onSubmit={handleSubmit}
      noValidate
      className="grid gap-4"
      aria-busy={submitting}
    >
      {/* Honeypot */}
      <div
        aria-hidden
        style={{ position: "absolute", left: "-9999px", height: 0, width: 0, overflow: "hidden" }}
      >
        <label htmlFor="website">Site</label>
        <input
          type="text"
          id="website"
          name="website"
          tabIndex={-1}
          autoComplete="off"
          value={values.website}
          onChange={(e) => update("website", e.target.value)}
        />
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          id="nome"
          label="Nome"
          required
          value={values.nome}
          onChange={(v) => update("nome", v)}
          error={errors.nome}
          autoComplete="name"
        />
        <Field
          id="email"
          label="E-mail"
          type="email"
          required
          value={values.email}
          onChange={(v) => update("email", v)}
          error={errors.email}
          autoComplete="email"
        />
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          id="telefone"
          label="Telefone/WhatsApp"
          required
          value={values.telefone}
          onChange={(v) => update("telefone", v)}
          error={errors.telefone}
          autoComplete="tel"
          inputMode="tel"
        />
        <Field
          id="empresa"
          label="Empresa"
          required
          value={values.empresa}
          onChange={(v) => update("empresa", v)}
          error={errors.empresa}
          autoComplete="organization"
        />
      </div>

      <div>
        <label
          htmlFor="tipoOperacao"
          className="block text-sm font-medium mb-1.5"
        >
          Tipo de operação{" "}
          <span className="text-red-700" aria-hidden>
            *
          </span>
        </label>
        <select
          id="tipoOperacao"
          name="tipoOperacao"
          required
          value={values.tipoOperacao}
          onChange={(e) => update("tipoOperacao", e.target.value)}
          className="w-full rounded-[5px] border border-(--color-border-light) bg-white px-4 py-3 text-sm focus:border-(--color-secondary) focus:outline-none focus:ring-2 focus:ring-(--color-accent)/30 transition-colors"
          aria-invalid={!!errors.tipoOperacao}
          aria-describedby={errors.tipoOperacao ? "tipoOperacao-error" : undefined}
        >
          <option value="">Selecione…</option>
          {tipoOperacaoOptions.map((o) => (
            <option key={o} value={o}>
              {o}
            </option>
          ))}
        </select>
        {errors.tipoOperacao && (
          <p id="tipoOperacao-error" className="mt-1 text-xs text-red-700">
            {errors.tipoOperacao}
          </p>
        )}
      </div>

      <div>
        <label
          htmlFor="mensagem"
          className="block text-sm font-medium mb-1.5"
        >
          Mensagem{" "}
          <span className="text-red-700" aria-hidden>
            *
          </span>
        </label>
        <textarea
          id="mensagem"
          name="mensagem"
          rows={5}
          required
          value={values.mensagem}
          onChange={(e) => update("mensagem", e.target.value)}
          placeholder="Conte sobre sua operação, tipo de resíduo gerado, periodicidade, etc."
          className="w-full rounded-[5px] border border-(--color-border-light) bg-white px-4 py-3 text-sm focus:border-(--color-secondary) focus:outline-none focus:ring-2 focus:ring-(--color-accent)/30 transition-colors resize-y min-h-[120px]"
          aria-invalid={!!errors.mensagem}
          aria-describedby={errors.mensagem ? "mensagem-error" : undefined}
        />
        {errors.mensagem && (
          <p id="mensagem-error" className="mt-1 text-xs text-red-700">
            {errors.mensagem}
          </p>
        )}
      </div>

      {status.kind === "error" && (
        <p
          role="alert"
          className="rounded-[10px] bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm"
        >
          {status.message}
        </p>
      )}

      <div className="mt-2">
        <Button
          type="submit"
          variant="primary"
          disabled={submitting}
          iconRight={<ArrowRightIcon width={18} height={18} />}
        >
          {submitting ? "Enviando…" : "Enviar mensagem"}
        </Button>
        <p className="mt-3 text-xs text-(--color-text-muted)">
          Ao enviar, você concorda em receber retorno da equipe Ecoleva. Saiba
          como tratamos seus dados na{" "}
          <Link
            href="/politica-de-privacidade"
            className="font-semibold text-(--color-secondary) underline underline-offset-2 hover:no-underline"
          >
            Política de Privacidade
          </Link>
          .
        </p>
      </div>
    </form>
  );
}

function Field({
  id,
  label,
  required,
  type = "text",
  value,
  onChange,
  error,
  autoComplete,
  inputMode,
}: {
  id: string;
  label: string;
  required?: boolean;
  type?: string;
  value: string;
  onChange: (v: string) => void;
  error?: string;
  autoComplete?: string;
  inputMode?: React.HTMLAttributes<HTMLInputElement>["inputMode"];
}) {
  return (
    <div>
      <label htmlFor={id} className="block text-sm font-medium mb-1.5">
        {label}
        {required && (
          <span className="text-red-700" aria-hidden>
            {" "}
            *
          </span>
        )}
      </label>
      <input
        id={id}
        name={id}
        type={type}
        required={required}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        autoComplete={autoComplete}
        inputMode={inputMode}
        aria-invalid={!!error}
        aria-describedby={error ? `${id}-error` : undefined}
        className="w-full rounded-[5px] border border-(--color-border-light) bg-white px-4 py-3 text-sm focus:border-(--color-secondary) focus:outline-none focus:ring-2 focus:ring-(--color-accent)/30 transition-colors"
      />
      {error && (
        <p id={`${id}-error`} className="mt-1 text-xs text-red-700">
          {error}
        </p>
      )}
    </div>
  );
}
