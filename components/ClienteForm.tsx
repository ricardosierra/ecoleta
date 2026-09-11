"use client";

import { useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { apiPostJson } from "@/lib/dashboard-api";
import { normalizePhone } from "@/lib/phone";

export type Client = {
  id: number;
  name: string;
  email: string | null;
  whatsapp: string | null;
  document: string | null;
  monthly_value: number;
  due_day: number;
  status: "active" | "inactive";
};

type ClienteFormProps = {
  initialData?: Client | null;
  onSuccess?: (client: Client) => void;
};

const inputClass =
  "mt-1.5 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/30 px-3.5 py-2.5 text-sm text-white outline-none transition-colors placeholder:text-white/30 focus:border-[var(--color-accent)]";

const labelClass =
  "block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)]";

export function ClienteForm({ initialData, onSuccess }: ClienteFormProps) {
  const router = useRouter();
  const isEditing = Boolean(initialData);

  const initialHasBilling = initialData ? Number(initialData.monthly_value) > 0 : false;

  const [name, setName] = useState(initialData?.name ?? "");
  const [email, setEmail] = useState(initialData?.email ?? "");
  const [whatsapp, setWhatsapp] = useState(initialData?.whatsapp ?? "");
  const [document, setDocument] = useState(initialData?.document ?? "");
  const [hasMonthlyBilling, setHasMonthlyBilling] = useState(initialHasBilling);
  const [monthlyValue, setMonthlyValue] = useState(
    initialData && Number(initialData.monthly_value) > 0
      ? String(initialData.monthly_value)
      : ""
  );
  const [dueDay, setDueDay] = useState(
    initialData?.due_day ? String(initialData.due_day) : "10"
  );
  const [status, setStatus] = useState<"active" | "inactive">(
    initialData?.status ?? "active"
  );

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  const valorInputRef = useRef<HTMLInputElement>(null);

  const handleToggleBilling = () => {
    const nextState = !hasMonthlyBilling;
    setHasMonthlyBilling(nextState);
    setError("");

    if (nextState) {
      if (!monthlyValue || Number(monthlyValue) <= 0) {
        setMonthlyValue("");
      }
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setSuccess("");

    if (!name.trim()) {
      setError("Nome / Empresa * é obrigatório.");
      return;
    }

    if (hasMonthlyBilling) {
      const parsedValue = parseFloat(monthlyValue);
      if (!parsedValue || parsedValue <= 0) {
        setError("Informe um valor mensal válido maior que zero quando a cobrança estiver habilitada.");
        return;
      }
      if (!document.trim()) {
        setError("Cliente com cobrança mensal precisa de CPF ou CNPJ — o Asaas recusa gerar a fatura sem ele.");
        return;
      }
    }

    setSubmitting(true);

    try {
      const endpoint = isEditing ? "/api/clients/edit.php" : "/api/clients/index.php";
      const payload = {
        ...(isEditing && initialData ? { client_id: initialData.id } : {}),
        name: name.trim(),
        email: email.trim(),
        whatsapp: normalizePhone(whatsapp),
        document: document.trim(),
        monthly_value: hasMonthlyBilling ? parseFloat(monthlyValue) || 0 : 0,
        due_day: hasMonthlyBilling ? parseInt(dueDay, 10) || 10 : 10,
        ...(isEditing ? { status } : {})
      };

      const res = await apiPostJson(endpoint, payload);
      const data = await res.json();

      if (res.ok && data.ok) {
        const msg = isEditing
          ? "Cliente atualizado com sucesso!"
          : "Cliente cadastrado com sucesso.";
        setSuccess(msg);

        if (onSuccess) {
          onSuccess(data.client);
        } else {
          setTimeout(() => {
            router.push("/dashboard/clientes");
            router.refresh();
          }, 800);
        }
      } else {
        setError(data.error || "Erro ao salvar cliente.");
      }
    } catch {
      setError("Erro de comunicação com o servidor.");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      {error && (
        <div className="rounded-xl border border-red-500/40 bg-red-950/50 p-4 text-sm text-red-200">
          {error}
        </div>
      )}

      {success && (
        <div className="rounded-xl border border-[var(--color-accent)]/40 bg-[var(--color-accent)]/10 p-4 text-sm text-[var(--color-accent)]">
          {success}
        </div>
      )}

      {/* Dados Principais */}
      <div className="rounded-2xl border border-[var(--color-border-dark)] bg-[rgba(255,255,255,0.03)] p-6 space-y-4">
        <h3 className="text-base font-semibold text-white">Dados Cadastrais</h3>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div className="sm:col-span-2">
            <label className={labelClass} htmlFor="cliente-nome">
              Nome / Empresa *
            </label>
            <input
              id="cliente-nome"
              required
              value={name}
              onChange={e => setName(e.target.value)}
              placeholder="Ex: Condomínio Solar das Palmeiras"
              className={inputClass}
            />
          </div>

          <div>
            <label className={labelClass} htmlFor="cliente-email">
              E-mail
            </label>
            <input
              id="cliente-email"
              type="email"
              value={email}
              onChange={e => setEmail(e.target.value)}
              placeholder="contato@empresa.com.br"
              className={inputClass}
            />
          </div>

          <div>
            <label className={labelClass} htmlFor="cliente-whatsapp">
              WhatsApp
            </label>
            <input
              id="cliente-whatsapp"
              value={whatsapp}
              onChange={e => setWhatsapp(e.target.value)}
              onBlur={e => setWhatsapp(normalizePhone(e.target.value))}
              placeholder="Ex: 21999887766"
              className={inputClass}
            />
          </div>

          <div className="sm:col-span-2">
            <label className={labelClass} htmlFor="cliente-documento">
              CPF/CNPJ{hasMonthlyBilling ? " *" : " (Opcional)"}
            </label>
            <input
              id="cliente-documento"
              required={hasMonthlyBilling}
              value={document}
              onChange={e => setDocument(e.target.value)}
              placeholder="00.000.000/0000-00 ou 000.000.000-00"
              className={inputClass}
            />
            {hasMonthlyBilling ? (
              <p className="text-xs text-[var(--color-text-on-dark)] mt-1.5">
                Obrigatório para emissão de faturas no Asaas quando a cobrança mensal estiver ativa.
              </p>
            ) : (
              <p className="text-xs text-white/40 mt-1.5">
                Opcional quando não há cobrança mensal recorrente.
              </p>
            )}
          </div>
        </div>
      </div>

      {/* Seção de Cobrança Mensal */}
      <div className="rounded-2xl border border-[var(--color-border-dark)] bg-[rgba(255,255,255,0.03)] p-6 space-y-5">
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-[var(--color-border-dark)]">
          <div>
            <div className="flex items-center gap-2.5">
              <h3 className="text-base font-semibold text-white">Cobrança Mensal Recorrente</h3>
              <span
                className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ${
                  hasMonthlyBilling
                    ? "bg-[var(--color-accent)]/15 text-[var(--color-accent)]"
                    : "bg-white/10 text-white/50"
                }`}
              >
                <span
                  className={`h-1.5 w-1.5 rounded-full ${
                    hasMonthlyBilling ? "bg-[var(--color-accent)]" : "bg-white/40"
                  }`}
                />
                {hasMonthlyBilling ? "Cobrança mensal habilitada" : "Cobrança mensal desabilitada"}
              </span>
            </div>
            <p className="text-xs text-[var(--color-text-on-dark)] mt-1">
              {hasMonthlyBilling
                ? "Cobrança mensal automática ativada no Asaas todo mês."
                : "Cliente avulso ou sem faturamento recorrente. Não é necessário definir valor ou vencimento."}
            </p>
          </div>

          <div className="flex items-center gap-3">
            <span className="text-sm font-medium text-white/70">
              {hasMonthlyBilling ? "Cobrança ativa" : "Sem cobrança"}
            </span>
            <button
              type="button"
              role="switch"
              aria-label="Habilitar ou desabilitar cobrança mensal"
              aria-checked={hasMonthlyBilling}
              onClick={handleToggleBilling}
              className={`relative inline-flex h-6 w-12 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)] focus:ring-offset-2 focus:ring-offset-[var(--color-bg-dark)] ${
                hasMonthlyBilling ? "bg-[var(--color-accent)]" : "bg-white/20"
              }`}
            >
              <span
                aria-hidden="true"
                className={`pointer-events-none inline-block h-5 w-5 transform rounded-full shadow ring-0 transition duration-200 ease-in-out ${
                  hasMonthlyBilling
                    ? "translate-x-6 bg-[var(--color-bg-dark)]"
                    : "translate-x-0 bg-white"
                }`}
              />
            </button>
          </div>
        </div>

        {/* Campos visíveis apenas se a cobrança mensal estiver habilitada */}
        {hasMonthlyBilling ? (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-1">
            <div>
              <label className={labelClass} htmlFor="cliente-valor">
                Valor Mensal Fixo (R$) *
              </label>
              <input
                ref={valorInputRef}
                id="cliente-valor"
                type="number"
                step="0.01"
                min="0.01"
                required={hasMonthlyBilling}
                value={monthlyValue}
                onChange={e => setMonthlyValue(e.target.value)}
                onWheel={e => e.currentTarget.blur()}
                placeholder="Ex: 250.00"
                className={inputClass}
              />
              <p className="text-xs text-white/40 mt-1.5">
                Valor líquido da assinatura ou contrato mensal.
              </p>
            </div>

            <div>
              <label className={labelClass} htmlFor="cliente-vencimento">
                Dia de Vencimento *
              </label>
              <input
                id="cliente-vencimento"
                type="number"
                min="1"
                max="31"
                required={hasMonthlyBilling}
                value={dueDay}
                onChange={e => setDueDay(e.target.value)}
                onWheel={e => e.currentTarget.blur()}
                className={inputClass}
              />
              <p className="text-xs text-white/40 mt-1.5">
                Dia do mês em que a fatura vence (1 a 31).
              </p>
            </div>
          </div>
        ) : (
          <div className="rounded-xl border border-white/10 bg-black/20 p-4 text-xs text-white/60">
            💡 Como a cobrança mensal está desabilitada, o cliente será registrado com valor R$ 0,00 e não entrará na régua de faturamento automático do Asaas.
          </div>
        )}
      </div>

      {/* Status (visível na edição) */}
      {isEditing && (
        <div className="rounded-2xl border border-[var(--color-border-dark)] bg-[rgba(255,255,255,0.03)] p-6 space-y-3">
          <label className={labelClass}>
            Status do Cadastro
          </label>
          <div className="flex items-center gap-4">
            <label className="inline-flex items-center gap-2 cursor-pointer text-sm text-white">
              <input
                type="radio"
                name="status"
                value="active"
                checked={status === "active"}
                onChange={() => setStatus("active")}
                className="accent-[var(--color-accent)]"
              />
              <span>Ativo</span>
            </label>
            <label className="inline-flex items-center gap-2 cursor-pointer text-sm text-white">
              <input
                type="radio"
                name="status"
                value="inactive"
                checked={status === "inactive"}
                onChange={() => setStatus("inactive")}
                className="accent-[var(--color-accent)]"
              />
              <span>Inativo</span>
            </label>
          </div>
          <p className="text-xs text-white/40">
            Clientes inativos não recebem cobranças automáticas nem aparecem nas listagens ativas de OS.
          </p>
        </div>
      )}

      {/* Botões de Ação */}
      <div className="flex flex-col-reverse sm:flex-row items-center justify-end gap-3 pt-2">
        <Link
          href="/dashboard/clientes"
          className="w-full sm:w-auto text-center px-6 py-2.5 rounded-full border border-white/15 text-sm font-medium text-white/70 hover:text-white hover:bg-white/5 transition"
        >
          Cancelar
        </Link>
        <button
          type="submit"
          disabled={submitting}
          className="w-full sm:w-auto bg-[var(--color-accent)] text-[var(--color-bg-dark)] px-8 py-2.5 rounded-full font-semibold hover:opacity-90 transition disabled:opacity-50 cursor-pointer"
        >
          {submitting ? "Salvando..." : isEditing ? "Atualizar Cliente" : "Salvar Cliente"}
        </button>
      </div>
    </form>
  );
}
