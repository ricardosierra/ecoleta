"use client";

import { useEffect, useMemo, useState } from "react";
import { DashboardGate, useDashboardAuth } from "@/components/DashboardGate";
import { canViewWhatsAppPanel } from "@/lib/authz";
import { apiPostJson } from "@/lib/dashboard-api";
import {
  deliveryMark,
  formatConversationStamp,
  formatMessageTime,
  formatPhone,
  groupMessagesByDay,
  initials,
  windowLabel,
  windowTone,
  type WhatsAppConversation,
  type WhatsAppMessage,
} from "@/lib/whatsapp";

/**
 * Painel de conversas do WhatsApp — leitura do que entrou pelo webhook e do que
 * saiu pelo robô.
 *
 * Layout e vocabulário visual vêm do banlek-whatsapp-service: lista à esquerda,
 * conversa à direita, faixa de janela no topo e bolhas com a paleta do próprio
 * WhatsApp. Ficou de fora o que aquele serviço tem e este projeto não: mídia,
 * transcrição de áudio, sugestões de IA e o painel de contexto.
 *
 * Só lê. Responder pelo painel exigiria mais uma superfície de envio e não faz
 * parte desta entrega.
 */
export default function WhatsAppPage() {
  return (
    <DashboardGate>
      <WhatsAppMain />
    </DashboardGate>
  );
}

const REFRESH_MS = 20000;

function WhatsAppMain() {
  const { user } = useDashboardAuth();
  const permitido = canViewWhatsAppPanel(user);

  const [conversations, setConversations] = useState<WhatsAppConversation[]>([]);
  const [activeId, setActiveId] = useState<number | null>(null);
  const [messages, setMessages] = useState<WhatsAppMessage[]>([]);
  const [activeConversation, setActiveConversation] = useState<WhatsAppConversation | null>(null);
  const [busca, setBusca] = useState("");
  const [erro, setErro] = useState("");

  useEffect(() => {
    if (!permitido) return;

    let cancelado = false;

    const carregar = async () => {
      try {
        const res = await fetch("/api/whatsapp/conversations.php");
        const data = await res.json();
        if (cancelado) return;

        if (res.ok && data.ok) {
          setConversations(data.conversations);
          setErro("");
        } else {
          setErro(data.error ?? "Não foi possível carregar as conversas.");
        }
      } catch {
        if (!cancelado) {
          setErro("Não foi possível carregar as conversas.");
        }
      }
    };

    void carregar();

    // A tela não tem push: uma releitura periódica é o que mantém a lista viva
    // enquanto alguém está olhando para ela.
    const timer = setInterval(() => void carregar(), REFRESH_MS);

    return () => {
      cancelado = true;
      clearInterval(timer);
    };
  }, [permitido]);

  useEffect(() => {
    if (!permitido || activeId === null) return;

    let cancelado = false;

    const carregar = async () => {
      try {
        const res = await fetch(`/api/whatsapp/messages.php?conversation_id=${activeId}`);
        const data = await res.json();
        if (!cancelado && res.ok && data.ok) {
          setMessages(data.messages);
          setActiveConversation(data.conversation);
        }
      } catch {
        // A lista continua na tela; o erro já aparece pelo carregamento dela.
      }
    };

    void carregar();
    const timer = setInterval(() => void carregar(), REFRESH_MS);

    return () => {
      cancelado = true;
      clearInterval(timer);
    };
  }, [permitido, activeId]);

  const abrirConversa = async (conversa: WhatsAppConversation) => {
    setActiveId(conversa.id);
    setActiveConversation(conversa);
    setMessages([]);

    if (conversa.unread_count > 0) {
      setConversations(lista =>
        lista.map(item => (item.id === conversa.id ? { ...item, unread_count: 0 } : item))
      );
      try {
        await apiPostJson("/api/whatsapp/messages.php", { conversation_id: conversa.id });
      } catch {
        // Marcar como lida é conveniência: falhar aqui não atrapalha a leitura.
      }
    }
  };

  const filtradas = useMemo(() => {
    const termo = busca.trim().toLowerCase();
    if (termo === "") return conversations;

    // Os dígitos só entram na comparação quando existem: `"".includes("")` é
    // verdadeiro, e uma busca por texto puro casaria com todo telefone da lista.
    const digitos = termo.replace(/\D/g, "");

    return conversations.filter(
      c =>
        c.name.toLowerCase().includes(termo) ||
        (digitos !== "" && c.phone.includes(digitos)) ||
        (c.last_message_preview ?? "").toLowerCase().includes(termo)
    );
  }, [conversations, busca]);

  const naoLidas = conversations.reduce((total, c) => total + c.unread_count, 0);

  if (!permitido) return <div className="p-8 text-white">Acesso negado.</div>;

  return (
    <div className="max-w-6xl mx-auto p-6 sm:p-8 space-y-6 text-white">
      <div className="flex items-end justify-between gap-4 flex-wrap">
        <div>
          <h1 className="text-3xl font-bold">WhatsApp</h1>
          <p className="text-[var(--color-text-on-dark)] mt-2">
            {conversations.length} conversa{conversations.length === 1 ? "" : "s"}
            {naoLidas > 0 && ` · ${naoLidas} não lida${naoLidas === 1 ? "" : "s"}`}
          </p>
        </div>
      </div>

      {erro && <p className="text-red-400 text-sm">{erro}</p>}

      <div className="grid grid-cols-1 md:grid-cols-[320px_1fr] rounded-2xl overflow-hidden border border-[var(--color-border-dark)] h-[70vh] min-h-[520px]">
        {/* Lista de conversas */}
        <aside className="bg-white text-[var(--color-text)] flex flex-col min-h-0 border-r border-[var(--color-wa-line)]">
          <div className="p-3 border-b border-[var(--color-wa-line)]">
            <input
              value={busca}
              onChange={e => setBusca(e.target.value)}
              placeholder="Buscar"
              aria-label="Buscar conversa"
              className="w-full bg-[var(--color-wa-panel)] rounded-full px-4 py-2 text-sm outline-none focus:ring-2 focus:ring-[var(--color-wa-teal)]/30"
            />
          </div>

          <ul className="flex-1 overflow-y-auto">
            {filtradas.length === 0 ? (
              <li className="p-6 text-center text-sm text-[var(--color-text-muted)]">
                {conversations.length === 0 ? "Nenhuma conversa ainda." : "Nada encontrado."}
              </li>
            ) : (
              filtradas.map(conversa => {
                const tom = windowTone(conversa.window);
                const ativa = conversa.id === activeId;

                return (
                  <li key={conversa.id}>
                    <button
                      onClick={() => void abrirConversa(conversa)}
                      aria-current={ativa ? "true" : undefined}
                      className={`w-full text-left flex gap-3 px-3 py-3 border-b border-[var(--color-wa-line)]/60 transition ${
                        ativa ? "bg-[#eef6ff]" : "hover:bg-[#f8fbff]"
                      }`}
                    >
                      <span className="relative shrink-0">
                        <span className="w-11 h-11 rounded-xl bg-[#e3e7ed] text-[#2b3241] font-bold text-sm flex items-center justify-center">
                          {initials(conversa.name, conversa.phone)}
                        </span>
                        <span
                          aria-hidden="true"
                          className={`absolute -right-0.5 -bottom-0.5 w-3.5 h-3.5 rounded-full border-2 border-white ${
                            tom === "open"
                              ? "bg-[#16a34a]"
                              : tom === "soon"
                                ? "bg-[#d97706]"
                                : "bg-[#94a3b8]"
                          }`}
                        />
                      </span>

                      <span className="min-w-0 flex-1">
                        <span className="flex items-baseline justify-between gap-2">
                          <span className={`truncate text-sm ${conversa.unread_count > 0 ? "font-bold" : "font-semibold"}`}>
                            {conversa.name || formatPhone(conversa.phone)}
                          </span>
                          <span className="text-[11px] text-[var(--color-text-muted)] shrink-0">
                            {formatConversationStamp(conversa.last_message_at)}
                          </span>
                        </span>

                        <span className="flex items-center justify-between gap-2 mt-1">
                          <span className="truncate text-xs text-[#4d5667]">
                            {conversa.last_message_direction === "outgoing" && (
                              <span className="text-[var(--color-wa-time)]">✓ </span>
                            )}
                            {conversa.last_message_preview || formatPhone(conversa.phone)}
                          </span>
                          {conversa.unread_count > 0 && (
                            <span className="shrink-0 min-w-5 h-5 px-1.5 rounded-full bg-[var(--color-wa-teal)] text-white text-[11px] font-bold flex items-center justify-center">
                              {conversa.unread_count}
                            </span>
                          )}
                        </span>
                      </span>
                    </button>
                  </li>
                );
              })
            )}
          </ul>
        </aside>

        {/* Conversa */}
        <section className="flex flex-col min-h-0 bg-[var(--color-wa-bg)]">
          {activeConversation ? (
            <>
              <header className="shrink-0 bg-[var(--color-wa-panel)] border-b border-[var(--color-wa-line)] px-4 py-3 flex items-center gap-3">
                <span className="w-10 h-10 rounded-xl bg-[#e3e7ed] text-[#2b3241] font-bold text-sm flex items-center justify-center shrink-0">
                  {initials(activeConversation.name, activeConversation.phone)}
                </span>
                <span className="min-w-0">
                  <span className="block font-bold text-[var(--color-text)] truncate">
                    {activeConversation.name || formatPhone(activeConversation.phone)}
                  </span>
                  <span className="block text-xs text-[var(--color-text-muted)]">
                    {formatPhone(activeConversation.phone)}
                    {activeConversation.client_name && ` · ${activeConversation.client_name}`}
                  </span>
                </span>
              </header>

              <WindowStrip conversation={activeConversation} />

              <div className="flex-1 overflow-y-auto px-4 sm:px-8 py-5">
                {messages.length === 0 ? (
                  <p className="text-center text-sm text-[var(--color-wa-time)]">Nenhuma mensagem.</p>
                ) : (
                  groupMessagesByDay(messages).map(grupo => (
                    <div key={grupo.day}>
                      <div className="flex justify-center my-4">
                        <span className="text-[11px] font-bold text-[var(--color-wa-time)] bg-white/80 rounded-lg px-2.5 py-1 shadow-sm">
                          {grupo.day}
                        </span>
                      </div>

                      {grupo.messages.map(mensagem => (
                        <Bubble key={mensagem.id} message={mensagem} />
                      ))}
                    </div>
                  ))
                )}
              </div>
            </>
          ) : (
            <div className="flex-1 flex items-center justify-center p-8 text-center text-[var(--color-wa-time)] text-sm">
              Selecione uma conversa
            </div>
          )}
        </section>
      </div>
    </div>
  );
}

/** Faixa que diz se dá para responder agora — o mesmo recado do painel da Banlek. */
function WindowStrip({ conversation }: { conversation: WhatsAppConversation }) {
  const tom = windowTone(conversation.window);

  const cor =
    tom === "open"
      ? "bg-[#e4f8ed] text-[#0e7a4c]"
      : tom === "soon"
        ? "bg-[#fff3d4] text-[#8a5a00]"
        : "bg-[#fdeae8] text-[#a32b1f]";

  return (
    <div className={`shrink-0 text-center text-xs font-bold py-2 px-4 ${cor}`}>
      {windowLabel(conversation.window)}
    </div>
  );
}

function Bubble({ message }: { message: WhatsAppMessage }) {
  const saindo = message.direction === "outgoing";
  const falhou = (message.status ?? "").toLowerCase() === "failed";

  return (
    <div className={`flex mb-2 ${saindo ? "justify-end" : "justify-start"}`}>
      <div
        className={`relative max-w-[min(560px,78%)] rounded-lg px-2.5 py-2 shadow-sm text-[var(--color-text)] ${
          saindo
            ? "bg-[var(--color-wa-out)] rounded-tr-none"
            : "bg-[var(--color-wa-in)] rounded-tl-none"
        }`}
      >
        {message.service_order_id !== null && message.service_order_id !== undefined && (
          <p className="text-[11px] font-bold text-[var(--color-wa-teal)] mb-1">
            OS Nº {String(message.service_order_id).padStart(5, "0")}
          </p>
        )}

        <p className="whitespace-pre-wrap break-words text-[14.5px] leading-snug">
          {message.body || `[${message.type ?? "sem conteúdo"}]`}
        </p>

        {falhou && message.error_message && (
          <p className="mt-1 text-[11px] text-[#b91c1c]">{message.error_message}</p>
        )}

        <p className="flex items-center justify-end gap-1 text-[11px] text-[var(--color-wa-time)] mt-0.5">
          <span>{formatMessageTime(message.message_at)}</span>
          {saindo && (
            <span
              className={falhou ? "text-[#ef4444]" : (message.status ?? "") === "read" ? "text-[#53bdeb]" : ""}
              title={message.status ?? undefined}
            >
              {deliveryMark(message.status)}
            </span>
          )}
        </p>
      </div>
    </div>
  );
}
