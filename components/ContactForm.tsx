"use client";

import { useState } from "react";
import Button from "@/components/Button";
import { ArrowRightIcon } from "@/components/icons";
import { tipoOperacaoOptions } from "@/lib/contact-schema";

type Status =
  | { kind: "idle" }
  | { kind: "submitting" }
  | { kind: "success" }
  | { kind: "error"; message: string };

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
      setErrors(localErrors);
      setStatus({ kind: "idle" });
      return;
    }

    try {
      const res = await fetch("/contact.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(values),
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
        setErrors(fieldErrors);
      }
      setStatus({
        kind: "error",
        message:
          data.error ||
          "Não foi possível enviar sua mensagem agora. Tente novamente ou fale pelo WhatsApp.",
      });
    } catch {
      setStatus({
        kind: "error",
        message:
          "Não foi possível enviar sua mensagem agora. Tente novamente ou fale pelo WhatsApp.",
      });
    }
  }

  if (status.kind === "success") {
    return (
      <div className="rounded-[10px] bg-(--color-bg-light) border border-(--color-accent) p-8 text-center">
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
          onClick={() => setStatus({ kind: "idle" })}
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
          Tipo de operação <span className="text-red-500">*</span>
        </label>
        <select
          id="tipoOperacao"
          name="tipoOperacao"
          required
          value={values.tipoOperacao}
          onChange={(e) => update("tipoOperacao", e.target.value)}
          className="w-full rounded-[5px] border border-(--color-border-light) bg-white px-4 py-3 text-sm focus:border-(--color-secondary) focus:outline-none focus:ring-2 focus:ring-(--color-accent)/30 transition-colors"
          aria-invalid={!!errors.tipoOperacao}
        >
          <option value="">Selecione…</option>
          {tipoOperacaoOptions.map((o) => (
            <option key={o} value={o}>
              {o}
            </option>
          ))}
        </select>
        {errors.tipoOperacao && (
          <p className="mt-1 text-xs text-red-500">{errors.tipoOperacao}</p>
        )}
      </div>

      <div>
        <label
          htmlFor="mensagem"
          className="block text-sm font-medium mb-1.5"
        >
          Mensagem <span className="text-red-500">*</span>
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
        />
        {errors.mensagem && (
          <p className="mt-1 text-xs text-red-500">{errors.mensagem}</p>
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
          Ao enviar, você concorda em receber retorno da equipe Ecoleva.
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
        {required && <span className="text-red-500"> *</span>}
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
        className="w-full rounded-[5px] border border-(--color-border-light) bg-white px-4 py-3 text-sm focus:border-(--color-secondary) focus:outline-none focus:ring-2 focus:ring-(--color-accent)/30 transition-colors"
      />
      {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
    </div>
  );
}
