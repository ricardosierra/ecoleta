"use client";

import Image from "next/image";
import { useEffect, useState } from "react";
import { DashboardGate, useDashboardAuth } from "@/components/DashboardGate";
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
    if (!clientId) return;

    const payload = {
      client_id: parseInt(clientId),
      weight,
      collection_date: collectionDate,
      bags_count: bagsCount,
      containers_count: containersCount,
      responsible
    };

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
        setFeedback({ tone: "erro", text: data.error ?? "Não foi possível gerar a OS." });
      }
    } catch {
      setFeedback({ tone: "erro", text: "Não foi possível gerar a OS." });
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

  return (
    <div className="max-w-6xl mx-auto p-6 sm:p-8 space-y-8 text-white">
      <div className="print:hidden">
        <h1 className="text-3xl font-bold">Ordem de Serviço (OS)</h1>
        <p className="text-[var(--color-text-on-dark)] mt-2">
          Gere OS de coleta para clientes fixos.
        </p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 print:hidden">
        {/* Formulário */}
        <div className="bg-[rgba(255,255,255,0.04)] rounded-2xl border border-[var(--color-border-dark)] p-6">
          <h2 className="text-xl font-semibold mb-4">Gerar Nova OS</h2>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]" htmlFor="os-cliente">Cliente *</label>
              <select id="os-cliente" required value={clientId} onChange={e => setClientId(e.target.value)} className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]">
                <option value="">Selecione...</option>
                {clients.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]" htmlFor="os-data">Data da Coleta</label>
                <input id="os-data" type="date" value={collectionDate} onChange={e => setCollectionDate(e.target.value)} className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
              </div>
              <div>
                <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]" htmlFor="os-peso">Pesagem</label>
                <input id="os-peso" value={weight} onChange={e => setWeight(e.target.value)} placeholder="Ex: 150 kg" className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
              </div>
              <div>
                <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]" htmlFor="os-sacos">Qtd. Sacos</label>
                <input id="os-sacos" type="number" value={bagsCount} onChange={e => setBagsCount(e.target.value)} className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
              </div>
              <div>
                <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]" htmlFor="os-containers">Qtd. Contêineres</label>
                <input id="os-containers" type="number" value={containersCount} onChange={e => setContainersCount(e.target.value)} className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
              </div>
            </div>
            <div>
              <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]" htmlFor="os-responsavel">Responsável pela Coleta</label>
              <input id="os-responsavel" value={responsible} onChange={e => setResponsible(e.target.value)} className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
            </div>
            <button type="submit" className="w-full bg-[var(--color-accent)] text-[var(--color-bg-dark)] px-5 py-3 rounded-full font-semibold hover:opacity-90 transition mt-2">
              Gerar OS
            </button>
          </form>
        </div>

        {/* Pré-visualização e Ações */}
        <div>
          {activeOS ? (
            <div className="space-y-4">
              <div className="flex justify-between items-center gap-3">
                <h2 className="text-xl font-semibold">OS Nº {osNumber(activeOS.id)}</h2>
                <button onClick={handlePrint} className="bg-white/10 hover:bg-white/20 px-4 py-2 rounded-full text-sm font-medium transition whitespace-nowrap">
                  🖨️ Imprimir / Salvar PDF
                </button>
              </div>

              {/* Encaminhamento */}
              <div className="bg-[rgba(255,255,255,0.04)] rounded-2xl border border-[var(--color-border-dark)] p-4 space-y-3">
                <div className="flex gap-2">
                  <input
                    type="email"
                    value={emailTo}
                    onChange={e => setEmailTo(e.target.value)}
                    placeholder="e-mail do destinatário"
                    aria-label="E-mail do destinatário"
                    className="flex-1 min-w-0 bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 text-sm outline-none focus:border-[var(--color-accent)]"
                  />
                  <button
                    onClick={handleEmail}
                    disabled={sending !== null}
                    className="bg-[var(--color-accent)] text-[var(--color-bg-dark)] px-4 py-2 rounded-full text-sm font-semibold hover:opacity-90 transition disabled:opacity-50 whitespace-nowrap"
                  >
                    {sending === "email" ? "Enviando…" : "✉️ E-mail"}
                  </button>
                </div>

                <div className="flex flex-wrap gap-2">
                  {/* Verde forte = janela de 24h aberta, envio gratuito. O
                      tooltip diz por quê; a cor é o que se lê de longe. */}
                  <button
                    onClick={() => handleWhatsAppRobo()}
                    disabled={sending !== null}
                    title={windowTooltip(activeOS.whatsapp_window)}
                    className={`px-4 py-2 rounded-full text-sm font-semibold hover:opacity-90 transition disabled:opacity-50 ${
                      activeOS.whatsapp_window?.open
                        ? "bg-[var(--color-accent)] text-[var(--color-bg-dark)]"
                        : "bg-white/10 text-white"
                    }`}
                  >
                    {sending === "whatsapp" ? "Enviando…" : "🤖 WhatsApp do robô"}
                  </button>
                  <button
                    onClick={handleWhatsAppPessoal}
                    className="bg-white/10 hover:bg-white/20 px-4 py-2 rounded-full text-sm font-medium transition"
                  >
                    📱 Meu WhatsApp
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
                  <dl className="text-xs text-[var(--color-text-on-dark)] space-y-1">
                    {activeOS.sent_at && (
                      <div className="flex gap-2">
                        <dt>✉️</dt>
                        <dd>{activeOS.sent_to} · {formatOsDateTime(activeOS.sent_at)}</dd>
                      </div>
                    )}
                    {activeOS.whatsapp_sent_at && (
                      <div className="flex gap-2">
                        <dt>🤖</dt>
                        <dd>{activeOS.whatsapp_sent_to} · {formatOsDateTime(activeOS.whatsapp_sent_at)}</dd>
                      </div>
                    )}
                  </dl>
                )}
              </div>

              {/* Box que será impresso. Uso CSS inline para garantir layout limpo na impressão se preciso, mas Tailwind lida bem com @media print */}
              <div id="os-print-area" className="bg-white text-black p-8 rounded-lg shadow-xl relative">
                <div className="flex justify-between items-start border-b-2 border-black/10 pb-6 mb-6">
                  <Logo variant="dark" height={40} />
                  <div className="text-right">
                    <h3 className="text-2xl font-bold text-[var(--color-secondary)] uppercase tracking-wider">Ordem de Serviço</h3>
                    <p className="text-sm text-gray-500 font-mono mt-1">Nº {osNumber(activeOS.id)}</p>
                  </div>
                </div>

                <div className="space-y-4 text-base">
                  <p><span className="font-semibold text-gray-700">Cliente:</span> {activeOS.client_name}</p>
                  <p><span className="font-semibold text-gray-700">Data da Coleta:</span> {formatOsDate(activeOS.collection_date)}</p>
                  <div className="grid grid-cols-2 gap-4">
                    <p><span className="font-semibold text-gray-700">Pesagem:</span> {osFieldValue(activeOS.weight)}</p>
                    <p><span className="font-semibold text-gray-700">Responsável:</span> {osFieldValue(activeOS.responsible)}</p>
                    <p><span className="font-semibold text-gray-700">Qtd. Sacos:</span> {osFieldValue(activeOS.bags_count)}</p>
                    <p><span className="font-semibold text-gray-700">Qtd. Contêineres:</span> {osFieldValue(activeOS.containers_count)}</p>
                  </div>
                </div>

                <div className="mt-20 text-center">
                  <Image
                    src="/assinatura-responsavel.png"
                    alt=""
                    width={700}
                    height={204}
                    className="mx-auto -mb-4 h-20 w-auto"
                  />
                  <div className="w-64 border-t-2 border-black/30 mx-auto mb-2"></div>
                  <p className="font-semibold text-gray-800">
                    {activeOS.signature_text || "Responsável Técnica - ECOLEVA"}
                  </p>
                </div>
              </div>
            </div>
          ) : (
            <div className="h-full border-2 border-dashed border-[var(--color-border-dark)] rounded-2xl flex items-center justify-center text-white/30 p-6 text-center">
              Nenhuma OS selecionada
            </div>
          )}
        </div>
      </div>

      {/* Histórico */}
      <div className="bg-[rgba(255,255,255,0.04)] rounded-2xl border border-[var(--color-border-dark)] overflow-hidden print:hidden mt-8">
        <div className="p-4 border-b border-[var(--color-border-dark)]">
          <h3 className="font-semibold">Histórico de OS Geradas</h3>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm whitespace-nowrap">
            <thead className="bg-black/40 text-[var(--color-text-on-dark)] border-b border-[var(--color-border-dark)]">
              <tr>
                <th className="px-6 py-3 font-medium">Nº</th>
                <th className="px-6 py-3 font-medium">Cliente</th>
                <th className="px-6 py-3 font-medium">Data</th>
                <th className="px-6 py-3 font-medium">Contêineres</th>
                <th className="px-6 py-3 font-medium">Envio</th>
                <th className="px-6 py-3 font-medium">Ações</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[var(--color-border-dark)]">
              {history.length === 0 ? (
                <tr><td colSpan={6} className="px-6 py-4 text-center text-white/50">Nenhuma OS encontrada.</td></tr>
              ) : history.map(os => (
                <tr key={os.id} className="hover:bg-white/5">
                  <td className="px-6 py-3">#{osNumber(os.id)}</td>
                  <td className="px-6 py-3">{os.client_name}</td>
                  <td className="px-6 py-3">{formatOsDate(os.collection_date)}</td>
                  <td className="px-6 py-3">{osFieldValue(os.containers_count)}</td>
                  <td className="px-6 py-3">
                    <span title={os.sent_to ? `E-mail: ${os.sent_to}` : undefined}>{os.sent_at ? "✉️" : ""}</span>
                    <span title={os.whatsapp_sent_to ? `WhatsApp: ${os.whatsapp_sent_to}` : undefined}>{os.whatsapp_sent_at ? "🤖" : ""}</span>
                    {!os.sent_at && !os.whatsapp_sent_at && <span className="text-white/30">—</span>}
                  </td>
                  <td className="px-6 py-3">
                    <button onClick={() => abrirOS(os)} className="text-[var(--color-accent)] hover:underline">Visualizar</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* Confirmação de reenvio pelo robô */}
      {reenvio && activeOS && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="os-reenvio-titulo"
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 print:hidden"
        >
          <div className="bg-[var(--color-bg-dark)] border border-[var(--color-border-dark)] rounded-2xl p-6 max-w-md w-full space-y-4">
            <h2 id="os-reenvio-titulo" className="text-lg font-semibold">
              OS Nº {osNumber(activeOS.id)} já enviada
            </h2>
            <p className="text-sm text-[var(--color-text-on-dark)]">
              O robô enviou esta OS para {reenvio.sentTo ?? "o cliente"} em {formatOsDateTime(reenvio.sentAt)}.
            </p>
            <div className="flex justify-end gap-2">
              <button
                onClick={() => setReenvio(null)}
                className="px-4 py-2 rounded-full text-sm font-medium bg-white/10 hover:bg-white/20 transition"
              >
                Cancelar
              </button>
              <button
                onClick={() => handleWhatsAppRobo(true)}
                disabled={sending !== null}
                className="px-4 py-2 rounded-full text-sm font-semibold bg-[var(--color-accent)] text-[var(--color-bg-dark)] hover:opacity-90 transition disabled:opacity-50"
              >
                {sending === "whatsapp" ? "Enviando…" : "Enviar novamente"}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Estilos para impressão */}
      <style dangerouslySetInnerHTML={{__html: `
        @media print {
          body * { visibility: hidden; }
          #os-print-area, #os-print-area * { visibility: visible; }
          #os-print-area { position: absolute; left: 0; top: 0; width: 100%; box-shadow: none; }
        }
      `}} />
    </div>
  );
}
