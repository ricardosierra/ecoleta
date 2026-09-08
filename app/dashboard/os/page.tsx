"use client";

import Image from "next/image";
import { useEffect, useState } from "react";
import { DashboardGate, useDashboardAuth } from "@/components/DashboardGate";
import { DashboardModal, ModalActions } from "@/components/DashboardModal";
import {
  BotIcon,
  FileTextIcon,
  MailIcon,
  PrinterIcon,
  SmartphoneIcon,
} from "@/components/icons";
import { isAdmin } from "@/lib/authz";
import { apiPostJson } from "@/lib/dashboard-api";
import {
  formatOsDate,
  formatOsDateTime,
  osFieldValue,
  osNumber,
  osWhatsAppLink,
  type ServiceOrder,
} from "@/lib/os-share";
import { windowTooltip } from "@/lib/whatsapp";
import Logo from "@/components/Logo";

type Client = { id: number; name: string };

type Feedback = { tone: "ok" | "erro"; text: string };

/** Dados do reenvio pendente de confirmação (resposta 409 de os/whatsapp.php). */
type ReenvioWhatsApp = { sentAt: string | null; sentTo: string | null };

const inputClass =
  "mt-1.5 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/30 px-3.5 py-2.5 text-sm text-white outline-none transition-colors placeholder:text-white/30 focus:border-[var(--color-accent)]";

const labelClass = "block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)]";

const cardClass = "rounded-2xl border border-[var(--color-border-dark)] bg-[rgba(255,255,255,0.04)]";

const secondaryButton =
  "inline-flex items-center justify-center gap-2 rounded-full bg-white/10 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-white/20 disabled:opacity-50";

export default function OSPage() {
  return (
    <DashboardGate>
      <OSMain />
    </DashboardGate>
  );
}

function OSMain() {
  const { user } = useDashboardAuth();
  const [clients, setClients] = useState<Client[]>([]);
  const [history, setHistory] = useState<ServiceOrder[]>([]);
  const isUserAdmin = isAdmin(user);

  // Form fields
  const [clientId, setClientId] = useState("");
  const [weight, setWeight] = useState("");
  const [collectionDate, setCollectionDate] = useState("");
  const [bagsCount, setBagsCount] = useState("");
  const [containersCount, setContainersCount] = useState("");
  const [responsible, setResponsible] = useState("");
  const [creating, setCreating] = useState(false);
  const [formError, setFormError] = useState("");

  const [activeOS, setActiveOS] = useState<ServiceOrder | null>(null);

  // Encaminhamento
  const [emailTo, setEmailTo] = useState("");
  const [sending, setSending] = useState<"email" | "whatsapp" | null>(null);
  const [feedback, setFeedback] = useState<Feedback | null>(null);
  const [reenvio, setReenvio] = useState<ReenvioWhatsApp | null>(null);

  useEffect(() => {
    if (!isUserAdmin) return;
    fetch("/api/clients/index.php").then(r => r.json()).then(d => { if(d.ok) setClients(d.clients); });
    fetch("/api/os/index.php").then(r => r.json()).then(d => { if(d.ok) setHistory(d.service_orders); });
  }, [isUserAdmin]);

  /** Abre uma OS na pré-visualização e zera o que era do documento anterior. */
  const abrirOS = (os: ServiceOrder) => {
    setActiveOS(os);
    setEmailTo(os.client_email ?? "");
    setFeedback(null);
    setReenvio(null);
  };

  /** Reflete um envio na pré-visualização e na linha do histórico. */
  const registrarEnvio = (id: number, campos: Partial<ServiceOrder>) => {
    setActiveOS(atual => (atual && atual.id === id ? { ...atual, ...campos } : atual));
    setHistory(lista => lista.map(os => (os.id === id ? { ...os, ...campos } : os)));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!clientId || creating) return;

    const payload = {
      client_id: parseInt(clientId),
      weight,
      collection_date: collectionDate,
      bags_count: bagsCount,
      containers_count: containersCount,
      responsible
    };

    setCreating(true);
    setFormError("");
    try {
      const res = await apiPostJson("/api/os/index.php", payload);
      const data = await res.json();
      if (res.ok && data.ok && data.service_order) {
        // A OS vem montada pelo servidor: é ela que traz o link com token, sem
        // o qual não há o que encaminhar.
        const criada: ServiceOrder = data.service_order;
        setHistory([criada, ...history]);
        abrirOS(criada);

        // Clear form
        setWeight("");
        setCollectionDate("");
        setBagsCount("");
        setContainersCount("");
        setResponsible("");
      } else {
        setFormError(data.error ?? "Não foi possível gerar a OS.");
      }
    } catch {
      setFormError("Não foi possível gerar a OS.");
    } finally {
      setCreating(false);
    }
  };

  const handlePrint = () => {
    window.print();
  };

  const handleEmail = async () => {
    if (!activeOS || sending) return;

    setSending("email");
    setFeedback(null);

    try {
      const res = await apiPostJson("/api/os/send.php", { id: activeOS.id, email: emailTo.trim() });
      const data = await res.json();

      if (res.ok && data.ok) {
        registrarEnvio(activeOS.id, { sent_at: data.sent_at ?? null, sent_to: data.sent_to ?? null });
        setFeedback({ tone: "ok", text: `Enviada para ${data.sent_to}.` });
      } else {
        setFeedback({ tone: "erro", text: data.error ?? "Não foi possível enviar o e-mail." });
      }
    } catch {
      setFeedback({ tone: "erro", text: "Não foi possível enviar o e-mail." });
    } finally {
      setSending(null);
    }
  };

  /**
   * WhatsApp do robô. Sem `confirm`, o servidor responde 409 quando a OS já foi
   * disparada antes — é o que abre a tela de confirmação. O segundo clique
   * repete a chamada com `confirm: true`.
   */
  const handleWhatsAppRobo = async (confirmar = false) => {
    if (!activeOS || sending) return;

    setSending("whatsapp");
    setFeedback(null);

    try {
      const res = await apiPostJson("/api/os/whatsapp.php", { id: activeOS.id, confirm: confirmar });
      const data = await res.json();

      if (res.ok && data.ok) {
        setReenvio(null);
        registrarEnvio(activeOS.id, {
          whatsapp_sent_at: data.whatsapp_sent_at ?? null,
          whatsapp_sent_to: data.whatsapp_sent_to ?? null,
        });
        setFeedback({ tone: "ok", text: `Enviada pelo robô para ${data.whatsapp_sent_to}.` });
      } else if (data.code === "whatsapp_already_sent") {
        setReenvio({ sentAt: data.whatsapp_sent_at ?? null, sentTo: data.whatsapp_sent_to ?? null });
      } else {
        setReenvio(null);
        setFeedback({ tone: "erro", text: data.error ?? "Não foi possível enviar pelo WhatsApp." });
      }
    } catch {
      setFeedback({ tone: "erro", text: "Não foi possível enviar pelo WhatsApp." });
    } finally {
      setSending(null);
    }
  };

  /** WhatsApp pessoal: abre o aplicativo do operador com o texto já escrito. */
  const handleWhatsAppPessoal = () => {
    if (!activeOS) return;
    window.open(osWhatsAppLink(activeOS), "_blank", "noopener,noreferrer");
  };

  if (!isUserAdmin) return <div className="p-8 text-white">Acesso negado.</div>;

  const janelaAberta = Boolean(activeOS?.whatsapp_window?.open);

  return (
    <div className="mx-auto max-w-6xl space-y-6 p-4 text-white sm:p-6 lg:p-8">
      <div className="print:hidden">
        <h1 className="text-2xl font-bold sm:text-3xl">Ordem de Serviço</h1>
        <p className="mt-1 text-sm text-[var(--color-text-on-dark)]">
          Gere a OS de coleta e encaminhe ao cliente.
        </p>
      </div>

      <div className="grid grid-cols-1 gap-6 print:hidden lg:grid-cols-2">
        {/* Formulário */}
        <div className={`${cardClass} p-5 sm:p-6`}>
          <h2 className="mb-4 text-lg font-semibold">Gerar Nova OS</h2>
          <form onSubmit={handleSubmit} className="space-y-4">
            <label className={labelClass} htmlFor="os-cliente">Cliente *
              <select id="os-cliente" required value={clientId} onChange={e => setClientId(e.target.value)} className={inputClass}>
                <option value="">Selecione...</option>
                {clients.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </label>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <label className={labelClass} htmlFor="os-data">Data da Coleta
                <input id="os-data" type="date" value={collectionDate} onChange={e => setCollectionDate(e.target.value)} className={inputClass} />
              </label>
              <label className={labelClass} htmlFor="os-peso">Pesagem
                <input id="os-peso" value={weight} onChange={e => setWeight(e.target.value)} placeholder="150 kg" className={inputClass} />
              </label>
              <label className={labelClass} htmlFor="os-sacos">Qtd. Sacos
                <input id="os-sacos" type="number" inputMode="numeric" min="0" value={bagsCount} onChange={e => setBagsCount(e.target.value)} className={inputClass} />
              </label>
              <label className={labelClass} htmlFor="os-containers">Qtd. Contêineres
                <input id="os-containers" type="number" inputMode="numeric" min="0" value={containersCount} onChange={e => setContainersCount(e.target.value)} className={inputClass} />
              </label>
            </div>
            <label className={labelClass} htmlFor="os-responsavel">Responsável pela Coleta
              <input id="os-responsavel" value={responsible} onChange={e => setResponsible(e.target.value)} className={inputClass} />
            </label>

            {formError && (
              <p role="alert" className="rounded-xl border border-red-500/40 bg-red-950/60 p-3 text-sm text-red-200">
                {formError}
              </p>
            )}

            <button
              type="submit"
              disabled={creating}
              className="mt-2 inline-flex w-full items-center justify-center gap-2 rounded-full bg-[var(--color-accent)] px-5 py-3 font-semibold text-[var(--color-bg-dark)] transition-opacity hover:opacity-90 disabled:opacity-50"
            >
              <FileTextIcon width={18} height={18} />
              {creating ? "Gerando…" : "Gerar OS"}
            </button>
          </form>
        </div>

        {/* Pré-visualização e Ações */}
        <div className="min-w-0">
          {activeOS ? (
            <div className="space-y-4">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="text-lg font-semibold">
                  OS <span className="font-mono text-[var(--color-accent)]">Nº {osNumber(activeOS.id)}</span>
                </h2>
                <button type="button" onClick={handlePrint} className={secondaryButton}>
                  <PrinterIcon width={16} height={16} />
                  Imprimir / PDF
                </button>
              </div>

              {/* Encaminhamento */}
              <div className={`${cardClass} space-y-3 p-4`}>
                <div className="flex flex-col gap-2 sm:flex-row">
                  <input
                    type="email"
                    value={emailTo}
                    onChange={e => setEmailTo(e.target.value)}
                    placeholder="e-mail do destinatário"
                    aria-label="E-mail do destinatário"
                    className="min-w-0 flex-1 rounded-xl border border-[var(--color-border-dark)] bg-black/30 px-3.5 py-2 text-sm text-white outline-none transition-colors placeholder:text-white/30 focus:border-[var(--color-accent)]"
                  />
                  <button
                    type="button"
                    onClick={handleEmail}
                    disabled={sending !== null}
                    className="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-full bg-[var(--color-accent)] px-4 py-2 text-sm font-semibold text-[var(--color-bg-dark)] transition-opacity hover:opacity-90 disabled:opacity-50"
                  >
                    <MailIcon width={16} height={16} />
                    {sending === "email" ? "Enviando…" : "E-mail"}
                  </button>
                </div>

                <div className="flex flex-wrap gap-2">
                  {/* Verde forte = janela de 24h aberta, envio gratuito. O
                      tooltip diz por quê; a cor é o que se lê de longe. */}
                  <button
                    type="button"
                    onClick={() => handleWhatsAppRobo()}
                    disabled={sending !== null}
                    title={windowTooltip(activeOS.whatsapp_window)}
                    className={`inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold transition-opacity hover:opacity-90 disabled:opacity-50 ${
                      janelaAberta
                        ? "bg-[var(--color-accent)] text-[var(--color-bg-dark)]"
                        : "bg-white/10 text-white"
                    }`}
                  >
                    <BotIcon width={16} height={16} />
                    {sending === "whatsapp" ? "Enviando…" : "WhatsApp do robô"}
                  </button>
                  <button type="button" onClick={handleWhatsAppPessoal} className={secondaryButton}>
                    <SmartphoneIcon width={16} height={16} />
                    Meu WhatsApp
                  </button>
                </div>

                {feedback && (
                  <p
                    role="status"
                    className={`text-sm ${feedback.tone === "ok" ? "text-[var(--color-accent)]" : "text-red-400"}`}
                  >
                    {feedback.text}
                  </p>
                )}

                {(activeOS.sent_at || activeOS.whatsapp_sent_at) && (
                  <dl className="space-y-1 text-xs text-[var(--color-text-on-dark)]">
                    {activeOS.sent_at && (
                      <div className="flex items-center gap-2">
                        <dt><MailIcon width={14} height={14} className="text-white/60" /><span className="sr-only">E-mail</span></dt>
                        <dd className="min-w-0 break-all">{activeOS.sent_to} · {formatOsDateTime(activeOS.sent_at)}</dd>
                      </div>
                    )}
                    {activeOS.whatsapp_sent_at && (
                      <div className="flex items-center gap-2">
                        <dt><BotIcon width={14} height={14} className="text-white/60" /><span className="sr-only">WhatsApp do robô</span></dt>
                        <dd className="min-w-0 break-all">{activeOS.whatsapp_sent_to} · {formatOsDateTime(activeOS.whatsapp_sent_at)}</dd>
                      </div>
                    )}
                  </dl>
                )}
              </div>

              {/* Documento — é o que sai na impressão. */}
              <div id="os-print-area" className="relative rounded-2xl bg-white p-5 text-black shadow-xl sm:p-8">
                <div className="mb-6 flex flex-wrap items-start justify-between gap-4 border-b-2 border-black/10 pb-6">
                  <Logo variant="dark" height={36} />
                  <div className="text-right">
                    <h3 className="text-xl font-bold uppercase tracking-wider text-[var(--color-secondary)] sm:text-2xl">Ordem de Serviço</h3>
                    <p className="mt-1 font-mono text-sm text-gray-500">Nº {osNumber(activeOS.id)}</p>
                  </div>
                </div>

                <div className="space-y-3 text-sm sm:text-base">
                  <p><span className="font-semibold text-gray-700">Cliente:</span> {activeOS.client_name}</p>
                  <p><span className="font-semibold text-gray-700">Data da Coleta:</span> {formatOsDate(activeOS.collection_date)}</p>
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <p><span className="font-semibold text-gray-700">Pesagem:</span> {osFieldValue(activeOS.weight)}</p>
                    <p><span className="font-semibold text-gray-700">Responsável:</span> {osFieldValue(activeOS.responsible)}</p>
                    <p><span className="font-semibold text-gray-700">Qtd. Sacos:</span> {osFieldValue(activeOS.bags_count)}</p>
                    <p><span className="font-semibold text-gray-700">Qtd. Contêineres:</span> {osFieldValue(activeOS.containers_count)}</p>
                  </div>
                </div>

                <div className="mt-14 text-center sm:mt-20">
                  <Image
                    src="/assinatura-responsavel.png"
                    alt=""
                    width={700}
                    height={204}
                    className="mx-auto -mb-4 h-20 w-auto"
                  />
                  <div className="mx-auto mb-2 w-56 border-t-2 border-black/30 sm:w-64"></div>
                  <p className="font-semibold text-gray-800">
                    {activeOS.signature_text || "Responsável Técnica - ECOLEVA"}
                  </p>
                </div>
              </div>
            </div>
          ) : (
            <div className="flex min-h-64 flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed border-[var(--color-border-dark)] p-6 text-center text-white/35 lg:h-full">
              <FileTextIcon width={36} height={36} className="text-white/20" />
              <p className="text-sm">Nenhuma OS selecionada</p>
            </div>
          )}
        </div>
      </div>

      {/* Histórico */}
      <section className={`${cardClass} overflow-hidden print:hidden`}>
        <div className="flex items-center justify-between gap-3 border-b border-[var(--color-border-dark)] p-4">
          <h3 className="font-semibold">Histórico de OS Geradas</h3>
          <span className="text-xs text-white/50">{history.length} {history.length === 1 ? "registro" : "registros"}</span>
        </div>
        <div className="data-table-wrap">
          <table className="data-table text-white/85">
          <thead className="bg-black/40 text-[var(--color-text-on-dark)]">
            <tr>
              <th>Nº</th>
              <th>Cliente</th>
              <th>Data</th>
              <th>Contêineres</th>
              <th>Envio</th>
              <th className="text-right">Ações</th>
            </tr>
          </thead>
          <tbody>
            {history.length === 0 ? (
              <tr><td colSpan={6} className="data-table-empty py-8 text-center text-sm text-white/50">Nenhuma OS encontrada.</td></tr>
            ) : history.map(os => {
              const ativa = activeOS?.id === os.id;
              return (
                <tr key={os.id} className={`transition-colors xl:hover:bg-white/5 ${ativa ? "bg-[var(--color-accent-soft)]" : ""}`}>
                  <td data-label="Nº" className="font-mono text-[var(--color-accent)]">#{osNumber(os.id)}</td>
                  <td data-label="Cliente" className="font-medium text-white">{os.client_name}</td>
                  <td data-label="Data" className="whitespace-nowrap">{formatOsDate(os.collection_date)}</td>
                  <td data-label="Contêineres">{osFieldValue(os.containers_count)}</td>
                  <td data-label="Envio">
                    <span className="inline-flex items-center gap-2">
                      {os.sent_at && (
                        <span title={os.sent_to ? `E-mail: ${os.sent_to}` : "E-mail"} className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-white/10 text-white/80">
                          <MailIcon width={14} height={14} />
                          <span className="sr-only">E-mail enviado</span>
                        </span>
                      )}
                      {os.whatsapp_sent_at && (
                        <span title={os.whatsapp_sent_to ? `WhatsApp: ${os.whatsapp_sent_to}` : "WhatsApp"} className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-[var(--color-accent-soft)] text-[var(--color-accent)]">
                          <BotIcon width={14} height={14} />
                          <span className="sr-only">WhatsApp enviado</span>
                        </span>
                      )}
                      {!os.sent_at && !os.whatsapp_sent_at && <span className="text-white/30">—</span>}
                    </span>
                  </td>
                  <td className="data-table-actions">
                    <button
                      type="button"
                      onClick={() => abrirOS(os)}
                      className="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold text-[var(--color-accent)] transition-colors hover:bg-[var(--color-accent-soft)]"
                    >
                      Visualizar
                    </button>
                  </td>
                </tr>
              );
            })}
          </tbody>
          </table>
        </div>
      </section>

      {/* Confirmação de reenvio pelo robô */}
      {reenvio && activeOS && (
        <DashboardModal
          title={`OS Nº ${osNumber(activeOS.id)} já enviada`}
          icon={<BotIcon width={18} height={18} />}
          tone="warning"
          size="sm"
          onClose={() => setReenvio(null)}
        >
          <p className="text-sm text-[var(--color-text-on-dark)]">
            O robô enviou esta OS para {reenvio.sentTo ?? "o cliente"} em {formatOsDateTime(reenvio.sentAt)}.
          </p>
          <ModalActions>
            <button type="button" onClick={() => setReenvio(null)} className={secondaryButton}>
              Cancelar
            </button>
            <button
              type="button"
              onClick={() => handleWhatsAppRobo(true)}
              disabled={sending !== null}
              className="inline-flex items-center justify-center rounded-full bg-[var(--color-accent)] px-4 py-2 text-sm font-semibold text-[var(--color-bg-dark)] transition-opacity hover:opacity-90 disabled:opacity-50"
            >
              {sending === "whatsapp" ? "Enviando…" : "Enviar novamente"}
            </button>
          </ModalActions>
        </DashboardModal>
      )}

      {/* Estilos para impressão */}
      <style dangerouslySetInnerHTML={{__html: `
        @media print {
          body * { visibility: hidden; }
          #os-print-area, #os-print-area * { visibility: visible; }
          #os-print-area { position: absolute; left: 0; top: 0; width: 100%; box-shadow: none; border-radius: 0; }
        }
      `}} />
    </div>
  );
}
