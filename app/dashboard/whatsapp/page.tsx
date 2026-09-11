"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { DashboardGate, useDashboardAuth } from "@/components/DashboardGate";
import { canViewWhatsAppPanel } from "@/lib/authz";
import { apiPostJson } from "@/lib/dashboard-api";
import {
  deliveryMark,
  formatAudioDuration,
  formatConversationStamp,
  formatMessageTime,
  formatPhone,
  groupMessagesByDay,
  initials,
  mimeTypeToCategory,
  windowLabel,
  windowTone,
  type WhatsAppClientOption,
  type WhatsAppConversation,
  type WhatsAppMessage,
  type WhatsAppTemplate,
} from "@/lib/whatsapp";

/**
 * Painel de conversas do WhatsApp — histórico, leitura e envio em tempo real.
 *
 * Paridade com o banlek-whatsapp-service:
 * - Listagem com busca, filtros de status (Todas/Abertas, Não lidas, Encerradas) e sincronização de clientes;
 * - Iniciar nova conversa (+) com cliente cadastrado ou número avulso;
 * - Composer completo para envio de texto livre quando a janela de 24h está aberta;
 * - Envio de mídia (imagens e documentos) e gravação/envio de áudio via microfone;
 * - Retomada oficial por template quando a janela de 24h está fechada;
 * - Ações de cabeçalho: encerrar/reabrir conversa, marcar como não lida, copiar link e transcrição.
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
  const [clientsList, setClientsList] = useState<WhatsAppClientOption[]>([]);
  const [activeId, setActiveId] = useState<number | null>(null);
  const [messages, setMessages] = useState<WhatsAppMessage[]>([]);
  const [activeConversation, setActiveConversation] = useState<WhatsAppConversation | null>(null);
  const [busca, setBusca] = useState("");
  const [activeTab, setActiveTab] = useState<"all" | "unread" | "closed">("all");
  const [erro, setErro] = useState("");
  const [toast, setToast] = useState<{ message: string; type: "success" | "error" } | null>(null);

  // Composer states
  const [replyText, setReplyText] = useState("");
  const [isSending, setIsSending] = useState(false);
  const [isUploadingFile, setIsUploadingFile] = useState(false);

  // Audio recording states
  const [isRecordingAudio, setIsRecordingAudio] = useState(false);
  const [recordingMs, setRecordingMs] = useState(0);

  // Modals
  const [modalNewChatOpen, setModalNewChatOpen] = useState(false);
  const [modalTemplateOpen, setModalTemplateOpen] = useState(false);

  // New Chat Form
  const [newChatClientId, setNewChatClientId] = useState<string>("");
  const [newChatPhone, setNewChatPhone] = useState("");
  const [newChatName, setNewChatName] = useState("");
  const [isStartingChat, setIsStartingChat] = useState(false);

  // Template Form
  const [templates, setTemplates] = useState<WhatsAppTemplate[]>([]);
  const [selectedTemplate, setSelectedTemplate] = useState<WhatsAppTemplate | null>(null);
  const [templateValues, setTemplateValues] = useState<Record<string, string>>({});
  const [isSendingTemplate, setIsSendingTemplate] = useState(false);

  // Refs
  const messagesEndRef = useRef<HTMLDivElement | null>(null);
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const mediaRecorderRef = useRef<MediaRecorder | null>(null);
  const audioStreamRef = useRef<MediaStream | null>(null);
  const audioChunksRef = useRef<Blob[]>([]);
  const recordingTimerRef = useRef<NodeJS.Timeout | null>(null);

  const showToast = (message: string, type: "success" | "error" = "success") => {
    setToast({ message, type });
    setTimeout(() => {
      setToast(prev => (prev?.message === message ? null : prev));
    }, 3500);
  };

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView?.({ behavior: "smooth" });
  };

  useEffect(() => {
    scrollToBottom();
  }, [messages]);

  // Carregar lista de conversas
  useEffect(() => {
    if (!permitido) return;
    let cancelado = false;

    const carregar = async () => {
      try {
        const res = await fetch("/api/whatsapp/conversations.php");
        const data = await res.json();
        if (cancelado) return;

        if (res.ok && data.ok) {
          setConversations(data.conversations ?? []);
          if (Array.isArray(data.clients)) {
            setClientsList(data.clients);
          }
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
    const timer = setInterval(() => void carregar(), REFRESH_MS);

    return () => {
      cancelado = true;
      clearInterval(timer);
    };
  }, [permitido]);

  // Carregar mensagens da conversa ativa
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
        // Falha silenciosa em background
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
        // Marcar como lida é conveniência
      }
    }
  };

  // Envio de mensagem de texto livre
  const handleSendText = async () => {
    const texto = replyText.trim();
    if (!texto || !activeId || isSending) return;

    if (!activeConversation?.window.open) {
      showToast("A janela de 24h está fechada. Envie um template para retomar.", "error");
      return;
    }

    setIsSending(true);
    try {
      const res = await apiPostJson("/api/whatsapp/messages.php", {
        conversation_id: activeId,
        body: texto,
      });
      const data = await res.json().catch(() => null);

      if (res.ok && data?.ok && data.message) {
        const msg = data.message as WhatsAppMessage;
        setMessages(prev => [...prev, msg]);
        setReplyText("");
        setConversations(lista =>
          lista.map(c =>
            c.id === activeId
              ? {
                  ...c,
                  last_message_at: msg.message_at,
                  last_message_preview: texto,
                  last_message_direction: "outgoing",
                }
              : c
          )
        );
        showToast("Mensagem enviada com sucesso!", "success");
      } else {
        throw new Error(data?.error || "Erro ao enviar mensagem.");
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Erro ao enviar mensagem.";
      showToast(msg, "error");
    } finally {
      setIsSending(false);
    }
  };

  // Envio de arquivos de mídia (imagem, documento, áudio)
  const handleFileChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file || !activeId || isUploadingFile) return;

    if (!activeConversation?.window.open) {
      showToast("A janela de 24h está fechada. Mídia só pode ser enviada com a janela aberta.", "error");
      return;
    }

    if (file.size > 16 * 1024 * 1024) {
      showToast("O arquivo não pode exceder 16 MB.", "error");
      return;
    }

    setIsUploadingFile(true);
    showToast(`Enviando ${file.name}...`, "success");

    try {
      const base64 = await new Promise<string>((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result || ""));
        reader.onerror = reject;
        reader.readAsDataURL(file);
      });

      const mediaType = mimeTypeToCategory(file.type || "");

      const res = await apiPostJson("/api/whatsapp/media.php", {
        conversation_id: activeId,
        type: mediaType,
        mime_type: file.type,
        filename: file.name,
        media_base64: base64,
      });
      const data = await res.json().catch(() => null);

      if (res.ok && data?.ok && data.message) {
        const msg = data.message as WhatsAppMessage;
        setMessages(prev => [...prev, msg]);
        setConversations(lista =>
          lista.map(c =>
            c.id === activeId
              ? {
                  ...c,
                  last_message_at: msg.message_at,
                  last_message_preview: msg.body,
                  last_message_direction: "outgoing",
                }
              : c
          )
        );
        showToast("Arquivo enviado com sucesso!", "success");
      } else {
        throw new Error(data?.error || "Erro ao enviar arquivo.");
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Erro ao enviar arquivo.";
      showToast(msg, "error");
    } finally {
      setIsUploadingFile(false);
      if (fileInputRef.current) {
        fileInputRef.current.value = "";
      }
    }
  };

  // Gravação de áudio pelo microfone
  const startAudioRecording = async () => {
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === "undefined") {
      showToast("Gravação de áudio não suportada neste navegador.", "error");
      return;
    }

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      audioStreamRef.current = stream;
      audioChunksRef.current = [];

      const options: MediaRecorderOptions = {};
      if (MediaRecorder.isTypeSupported("audio/webm;codecs=opus")) {
        options.mimeType = "audio/webm;codecs=opus";
      } else if (MediaRecorder.isTypeSupported("audio/ogg;codecs=opus")) {
        options.mimeType = "audio/ogg;codecs=opus";
      } else if (MediaRecorder.isTypeSupported("audio/mp4")) {
        options.mimeType = "audio/mp4";
      }

      const recorder = new MediaRecorder(stream, options);
      mediaRecorderRef.current = recorder;

      recorder.ondataavailable = event => {
        if (event.data && event.data.size > 0) {
          audioChunksRef.current.push(event.data);
        }
      };

      const startTime = Date.now();
      setRecordingMs(0);
      setIsRecordingAudio(true);

      recordingTimerRef.current = setInterval(() => {
        setRecordingMs(Date.now() - startTime);
      }, 300);

      recorder.start(250);
    } catch {
      showToast("Não foi possível acessar o microfone. Verifique as permissões.", "error");
    }
  };

  const stopAudioRecording = (cancel = false) => {
    if (recordingTimerRef.current) {
      clearInterval(recordingTimerRef.current);
      recordingTimerRef.current = null;
    }

    const recorder = mediaRecorderRef.current;
    const stream = audioStreamRef.current;

    setIsRecordingAudio(false);

    if (!recorder) {
      if (stream) stream.getTracks().forEach(t => t.stop());
      return;
    }

    recorder.onstop = async () => {
      if (stream) stream.getTracks().forEach(t => t.stop());
      audioStreamRef.current = null;
      mediaRecorderRef.current = null;

      if (cancel) {
        audioChunksRef.current = [];
        showToast("Gravação cancelada.", "success");
        return;
      }

      const mimeType = recorder.mimeType || "audio/ogg";
      const blob = new Blob(audioChunksRef.current, { type: mimeType });
      audioChunksRef.current = [];

      if (blob.size < 300) {
        showToast("Áudio muito curto.", "error");
        return;
      }

      showToast("Enviando áudio gravado...", "success");
      try {
        const base64 = await new Promise<string>((resolve, reject) => {
          const reader = new FileReader();
          reader.onload = () => resolve(String(reader.result || ""));
          reader.onerror = reject;
          reader.readAsDataURL(blob);
        });

        const res = await apiPostJson("/api/whatsapp/media.php", {
          conversation_id: activeId,
          type: "audio",
          mime_type: mimeType,
          filename: `audio_${Date.now()}.ogg`,
          media_base64: base64,
        });
        const data = await res.json().catch(() => null);

        if (res.ok && data?.ok && data.message) {
          const msg = data.message as WhatsAppMessage;
          setMessages(prev => [...prev, msg]);
          setConversations(lista =>
            lista.map(c =>
              c.id === activeId
                ? {
                    ...c,
                    last_message_at: msg.message_at,
                    last_message_preview: "[áudio]",
                    last_message_direction: "outgoing",
                  }
                : c
            )
          );
          showToast("Áudio enviado com sucesso!", "success");
        } else {
          throw new Error(data?.error || "Erro ao enviar áudio.");
        }
      } catch (err: unknown) {
        const msg = err instanceof Error ? err.message : "Erro ao enviar áudio.";
        showToast(msg, "error");
      }
    };

    recorder.stop();
  };

  // Ações de cabeçalho: encerrar/reabrir
  const handleToggleStatus = async () => {
    if (!activeId || !activeConversation) return;

    try {
      const res = await apiPostJson("/api/whatsapp/conversations.php", {
        action: "toggle_status",
        conversation_id: activeId,
      });
      const data = await res.json().catch(() => null);

      if (res.ok && data?.ok && data.status) {
        const newStatus = String(data.status);
        setActiveConversation(prev => (prev ? { ...prev, status: newStatus } : prev));
        setConversations(lista =>
          lista.map(c => (c.id === activeId ? { ...c, status: newStatus } : c))
        );
        showToast(newStatus === "closed" ? "Conversa encerrada." : "Conversa reaberta.", "success");
      } else {
        throw new Error(data?.error || "Não foi possível alterar o status da conversa.");
      }
    } catch {
      showToast("Não foi possível alterar o status da conversa.", "error");
    }
  };

  // Ação de cabeçalho: marcar lida/não lida
  const handleToggleReadState = async () => {
    if (!activeId || !activeConversation) return;

    const unread = activeConversation.unread_count > 0;
    try {
      if (unread) {
        await apiPostJson("/api/whatsapp/messages.php", { conversation_id: activeId });
        setActiveConversation(prev => (prev ? { ...prev, unread_count: 0 } : prev));
        setConversations(lista =>
          lista.map(c => (c.id === activeId ? { ...c, unread_count: 0 } : c))
        );
        showToast("Conversa marcada como lida.", "success");
      } else {
        await apiPostJson("/api/whatsapp/messages.php", {
          action: "mark_unread",
          conversation_id: activeId,
        });
        setActiveConversation(prev => (prev ? { ...prev, unread_count: 1 } : prev));
        setConversations(lista =>
          lista.map(c => (c.id === activeId ? { ...c, unread_count: 1 } : c))
        );
        showToast("Conversa marcada como não lida.", "success");
      }
    } catch {
      showToast("Erro ao alterar estado de leitura.", "error");
    }
  };

  // Copiar transcrição inteira
  const handleCopyTranscript = async () => {
    if (!activeConversation || messages.length === 0) {
      showToast("Sem mensagens para copiar.", "error");
      return;
    }

    const cabecalho = `Conversa: ${activeConversation.name || activeConversation.phone}\nTelefone: +${activeConversation.phone}\n\n`;
    const linhas = messages
      .map(m => {
        const remetente = m.direction === "outgoing" ? "Ecoleva" : activeConversation.name || "Cliente";
        const hora = formatMessageTime(m.message_at);
        return `[${hora}] ${remetente}: ${m.body || `[${m.type ?? "mídia"}]`}`;
      })
      .join("\n");

    try {
      await navigator.clipboard.writeText(cabecalho + linhas);
      showToast("Transcrição completa copiada!", "success");
    } catch {
      showToast("Falha ao copiar transcrição.", "error");
    }
  };

  // Iniciar nova conversa (Modal)
  const handleStartNewChat = async (e: React.FormEvent) => {
    e.preventDefault();
    if (isStartingChat) return;

    let phone = newChatPhone.trim();
    let name = newChatName.trim();
    let clientId: number | undefined = undefined;

    if (newChatClientId !== "") {
      const selectedClient = clientsList.find(cl => String(cl.id) === newChatClientId);
      if (selectedClient) {
        phone = selectedClient.phone;
        name = selectedClient.name;
        clientId = selectedClient.id;
      }
    }

    if (!phone) {
      showToast("Informe o telefone ou selecione um cliente cadastrado.", "error");
      return;
    }

    setIsStartingChat(true);
    try {
      const res = await apiPostJson(
        "/api/whatsapp/conversations.php",
        {
          action: "start",
          phone,
          name: name || undefined,
          client_id: clientId,
        }
      );
      const data = await res.json().catch(() => null);

      if (res.ok && data?.ok && data.conversation) {
        const nova = data.conversation as WhatsAppConversation;
        setConversations(prev => {
          const semEla = prev.filter(c => c.id !== nova.id);
          return [nova, ...semEla];
        });
        setActiveId(nova.id);
        setActiveConversation(nova);
        setMessages([]);
        setModalNewChatOpen(false);
        setNewChatPhone("");
        setNewChatName("");
        setNewChatClientId("");
        showToast("Conversa aberta com sucesso!", "success");
      } else {
        throw new Error(data?.error || "Erro ao abrir conversa.");
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Erro ao abrir conversa.";
      showToast(msg, "error");
    } finally {
      setIsStartingChat(false);
    }
  };

  // Abertura do modal de template
  const handleOpenTemplateModal = async () => {
    try {
      const res = await fetch("/api/whatsapp/templates.php");
      const data = await res.json();
      if (res.ok && data.ok && Array.isArray(data.templates)) {
        setTemplates(data.templates);
        const inicial = data.templates[0] || null;
        setSelectedTemplate(inicial);

        // Preenche com sugestões inteligentes
        if (inicial && activeConversation) {
          const vals: Record<string, string> = {};
          if (inicial.params_count >= 1) vals["1"] = activeConversation.name || "Cliente";
          if (inicial.params_count >= 2) vals["2"] = "00001";
          if (inicial.params_count >= 3) vals["3"] = "https://www.ecolevaeco.com";
          setTemplateValues(vals);
        }
      }
    } catch {
      // Ignora erro de fetch
    }
    setModalTemplateOpen(true);
  };

  // Disparo de template
  const handleSendTemplate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedTemplate || !activeId || isSendingTemplate) return;

    setIsSendingTemplate(true);
    try {
      const parameters = Array.from({ length: selectedTemplate.params_count }, (_, i) => {
        return templateValues[String(i + 1)] ?? "";
      });

      const res = await apiPostJson("/api/whatsapp/templates.php", {
        conversation_id: activeId,
        template_name: selectedTemplate.name,
        template_language: selectedTemplate.language || "pt_BR",
        parameters,
      });
      const data = await res.json().catch(() => null);

      if (res.ok && data?.ok && data.message) {
        const msg = data.message as WhatsAppMessage;
        setMessages(prev => [...prev, msg]);
        setConversations(lista =>
          lista.map(c =>
            c.id === activeId
              ? {
                  ...c,
                  last_message_at: msg.message_at,
                  last_message_preview: msg.body,
                  last_message_direction: "outgoing",
                }
              : c
          )
        );
        setModalTemplateOpen(false);
        showToast("Template enviado com sucesso!", "success");
      } else {
        throw new Error(data?.error || "Erro ao enviar template.");
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Erro ao enviar template.";
      showToast(msg, "error");
    } finally {
      setIsSendingTemplate(false);
    }
  };

  // Filtragem da lista
  const filtradas = useMemo(() => {
    let lista = conversations;

    if (activeTab === "unread") {
      lista = lista.filter(c => c.unread_count > 0);
    } else if (activeTab === "closed") {
      lista = lista.filter(c => c.status === "closed");
    } else {
      // "all" ou abertas
      lista = lista.filter(c => c.status !== "closed");
    }

    const termo = busca.trim().toLowerCase();
    if (termo === "") return lista;

    const digitos = termo.replace(/\D/g, "");

    return lista.filter(
      c =>
        c.name.toLowerCase().includes(termo) ||
        (digitos !== "" && c.phone.includes(digitos)) ||
        (c.last_message_preview ?? "").toLowerCase().includes(termo)
    );
  }, [conversations, busca, activeTab]);

  const naoLidas = conversations.reduce((total, c) => total + c.unread_count, 0);

  if (!permitido) return <div className="p-8 text-white">Acesso negado.</div>;

  return (
    <div className="max-w-6xl mx-auto p-4 sm:p-8 space-y-4 text-white">
      {/* Toast Notification */}
      {toast && (
        <div
          className={`fixed bottom-6 right-6 z-50 px-5 py-3 rounded-xl shadow-2xl text-sm font-semibold flex items-center gap-3 transition-all animate-fade-in ${
            toast.type === "error" ? "bg-red-600 text-white" : "bg-[#118c7e] text-white"
          }`}
        >
          <span>{toast.message}</span>
        </div>
      )}

      {/* Top Header */}
      <div className="flex items-end justify-between gap-4 flex-wrap">
        <div>
          <h1 className="text-3xl font-bold">WhatsApp</h1>
          <p className="text-[var(--color-text-on-dark)] mt-1">
            {conversations.length} conversa{conversations.length === 1 ? "" : "s"}
            {naoLidas > 0 && ` · ${naoLidas} não lida${naoLidas === 1 ? "" : "s"}`}
          </p>
        </div>

        <button
          onClick={() => setModalNewChatOpen(true)}
          className="inline-flex items-center gap-2 bg-[#16a34a] hover:bg-[#15803d] text-white px-5 py-2.5 rounded-full font-semibold text-sm transition shadow-md cursor-pointer"
        >
          <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
            <line x1="12" y1="5" x2="12" y2="19" />
            <line x1="5" y1="12" x2="19" y2="12" />
          </svg>
          Nova conversa
        </button>
      </div>

      {erro && <p className="text-red-400 text-sm">{erro}</p>}

      {/* Chat Container */}
      <div className="grid grid-cols-1 md:grid-cols-[330px_1fr] rounded-2xl overflow-hidden border border-[var(--color-border-dark)] h-[75vh] min-h-[560px]">
        {/* Sidebar */}
        <aside className="bg-white text-[var(--color-text)] flex flex-col min-h-0 border-r border-[var(--color-wa-line)]">
          {/* Header com busca e abas */}
          <div className="p-3 border-b border-[var(--color-wa-line)] space-y-2 bg-[#fbfcfe]">
            <div className="flex items-center gap-2">
              <input
                value={busca}
                onChange={e => setBusca(e.target.value)}
                placeholder="Buscar conversa"
                aria-label="Buscar conversa"
                className="flex-1 bg-[var(--color-wa-panel)] rounded-full px-4 py-2 text-sm outline-none focus:ring-2 focus:ring-[var(--color-wa-teal)]/30 border border-gray-200"
              />
              <button
                type="button"
                onClick={() => setModalNewChatOpen(true)}
                title="Nova conversa"
                aria-label="Nova conversa"
                className="w-9 h-9 rounded-full bg-[var(--color-wa-teal)] text-white flex items-center justify-center font-bold text-lg hover:brightness-95 transition shrink-0 cursor-pointer shadow-sm"
              >
                +
              </button>
            </div>

            {/* Abas de filtro */}
            <div className="flex gap-1 pt-1">
              <button
                type="button"
                onClick={() => setActiveTab("all")}
                className={`flex-1 py-1.5 text-xs font-bold rounded-lg transition ${
                  activeTab === "all" ? "bg-[#eef6ff] text-[#1d7afc]" : "text-gray-500 hover:bg-gray-100"
                }`}
              >
                Abertas
              </button>
              <button
                type="button"
                onClick={() => setActiveTab("unread")}
                className={`flex-1 py-1.5 text-xs font-bold rounded-lg transition flex items-center justify-center gap-1 ${
                  activeTab === "unread" ? "bg-[#eef6ff] text-[#1d7afc]" : "text-gray-500 hover:bg-gray-100"
                }`}
              >
                <span>Não lidas</span>
                {naoLidas > 0 && (
                  <span className="text-[10px] font-bold text-[var(--color-wa-teal)]">
                    ({naoLidas})
                  </span>
                )}
              </button>
              <button
                type="button"
                onClick={() => setActiveTab("closed")}
                className={`flex-1 py-1.5 text-xs font-bold rounded-lg transition ${
                  activeTab === "closed" ? "bg-[#eef6ff] text-[#1d7afc]" : "text-gray-500 hover:bg-gray-100"
                }`}
              >
                Encerradas
              </button>
            </div>
          </div>

          {/* Lista de conversas */}
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
                      className={`w-full text-left flex gap-3 px-3 py-3 border-b border-[var(--color-wa-line)]/60 transition cursor-pointer ${
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
                            conversa.status === "closed"
                              ? "bg-gray-400"
                              : tom === "open"
                                ? "bg-[#16a34a]"
                                : tom === "soon"
                                  ? "bg-[#d97706]"
                                  : "bg-[#94a3b8]"
                          }`}
                          title={
                            conversa.status === "closed"
                              ? "Conversa encerrada"
                              : tom === "open"
                                ? "Janela de 24h aberta"
                                : "Janela de 24h fechada"
                          }
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

        {/* Chat Panel */}
        <section className="flex flex-col min-h-0 bg-[var(--color-wa-bg)] relative">
          {activeConversation ? (
            <>
              {/* Chat Header */}
              <header className="shrink-0 bg-[var(--color-wa-panel)] border-b border-[var(--color-wa-line)] px-4 py-3 flex items-center justify-between gap-3 shadow-xs">
                <div className="flex items-center gap-3 min-w-0">
                  <span className="w-10 h-10 rounded-xl bg-[#e3e7ed] text-[#2b3241] font-bold text-sm flex items-center justify-center shrink-0">
                    {initials(activeConversation.name, activeConversation.phone)}
                  </span>
                  <div className="min-w-0">
                    <div className="font-bold text-[var(--color-text)] truncate flex items-center gap-2">
                      <span>{activeConversation.name || formatPhone(activeConversation.phone)}</span>
                      {activeConversation.status === "closed" && (
                        <span className="text-[10px] uppercase font-extrabold bg-gray-200 text-gray-700 px-2 py-0.5 rounded-full">
                          Encerrada
                        </span>
                      )}
                    </div>
                    <div className="text-xs text-[var(--color-text-muted)] truncate">
                      {formatPhone(activeConversation.phone)}
                      {activeConversation.client_name && ` · ${activeConversation.client_name}`}
                    </div>
                  </div>
                </div>

                {/* Header Action Buttons */}
                <div className="flex items-center gap-1.5 shrink-0 text-gray-600">
                  <button
                    type="button"
                    onClick={handleToggleStatus}
                    title={activeConversation.status === "closed" ? "Reabrir conversa" : "Encerrar conversa"}
                    className="p-2 hover:bg-gray-200 rounded-lg transition text-sm cursor-pointer"
                  >
                    {activeConversation.status === "closed" ? "🔓 Reabrir" : "🔒 Encerrar"}
                  </button>

                  <button
                    type="button"
                    onClick={handleToggleReadState}
                    title={activeConversation.unread_count > 0 ? "Marcar como lida" : "Marcar como não lida"}
                    className="p-2 hover:bg-gray-200 rounded-lg transition cursor-pointer"
                  >
                    <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <rect width="20" height="16" x="2" y="4" rx="2" />
                      <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
                    </svg>
                  </button>

                  <button
                    type="button"
                    onClick={handleCopyTranscript}
                    title="Copiar conversa inteira"
                    className="p-2 hover:bg-gray-200 rounded-lg transition cursor-pointer"
                  >
                    <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <rect width="14" height="14" x="8" y="8" rx="2" ry="2" />
                      <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
                    </svg>
                  </button>
                </div>
              </header>

              {/* Faixa de status da janela de 24h */}
              <WindowStrip conversation={activeConversation} />

              {/* Lista de Mensagens */}
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
                <div ref={messagesEndRef} />
              </div>

              {/* Composer no rodapé */}
              <footer className="shrink-0 bg-[var(--color-wa-panel)] border-t border-[var(--color-wa-line)] p-3">
                {activeConversation.status === "closed" ? (
                  <div className="bg-gray-100 border border-gray-300 rounded-xl p-3 flex items-center justify-between gap-3 text-gray-700 text-xs">
                    <span>Esta conversa foi encerrada. Reabra para enviar novas mensagens.</span>
                    <button
                      type="button"
                      onClick={handleToggleStatus}
                      className="bg-[#16a34a] hover:bg-[#15803d] text-white px-4 py-1.5 rounded-full font-bold transition cursor-pointer"
                    >
                      Reabrir Conversa
                    </button>
                  </div>
                ) : activeConversation.window.open ? (
                  <div className="flex items-end gap-2 relative">
                    {/* Input invisível para arquivos */}
                    <input
                      ref={fileInputRef}
                      type="file"
                      accept="image/*,application/pdf,audio/*"
                      onChange={handleFileChange}
                      className="hidden"
                    />

                    {/* Botão de Anexo */}
                    <button
                      type="button"
                      onClick={() => fileInputRef.current?.click()}
                      disabled={isUploadingFile || isRecordingAudio}
                      title="Anexar imagem, documento ou áudio"
                      aria-label="Anexar arquivo"
                      className="w-10 h-10 rounded-full flex items-center justify-center text-gray-500 hover:text-gray-800 hover:bg-gray-200 transition shrink-0 cursor-pointer disabled:opacity-40"
                    >
                      <svg className="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48" />
                      </svg>
                    </button>

                    {/* Botão de Gravar Áudio / Interface de Gravação */}
                    {isRecordingAudio ? (
                      <div className="flex-1 bg-red-50 border border-red-200 rounded-2xl px-4 py-2 flex items-center justify-between gap-3 text-red-600 text-sm">
                        <div className="flex items-center gap-2">
                          <span className="w-3 h-3 rounded-full bg-red-500 animate-pulse" />
                          <span className="font-bold">Gravando: {formatAudioDuration(recordingMs)}</span>
                        </div>
                        <div className="flex items-center gap-2">
                          <button
                            type="button"
                            onClick={() => stopAudioRecording(true)}
                            className="text-xs text-gray-500 hover:text-gray-700 underline font-semibold px-2 cursor-pointer"
                          >
                            Cancelar
                          </button>
                          <button
                            type="button"
                            onClick={() => stopAudioRecording(false)}
                            className="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded-full text-xs font-bold transition cursor-pointer"
                          >
                            Enviar Áudio
                          </button>
                        </div>
                      </div>
                    ) : (
                      <>
                        <button
                          type="button"
                          onClick={startAudioRecording}
                          disabled={isUploadingFile}
                          title="Gravar áudio"
                          aria-label="Gravar áudio"
                          className="w-10 h-10 rounded-full flex items-center justify-center text-gray-500 hover:text-gray-800 hover:bg-gray-200 transition shrink-0 cursor-pointer disabled:opacity-40"
                        >
                          <svg className="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                            <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
                            <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
                            <line x1="12" x2="12" y1="19" y2="22" />
                          </svg>
                        </button>

                        {/* Textarea de Digitação */}
                        <div className="flex-1 bg-white rounded-2xl border border-gray-200 shadow-xs focus-within:ring-2 focus-within:ring-[var(--color-wa-teal)]/30 focus-within:border-[var(--color-wa-teal)] flex flex-col min-h-[42px] max-h-36">
                          <textarea
                            value={replyText}
                            onChange={e => setReplyText(e.target.value)}
                            onKeyDown={e => {
                              if (e.key === "Enter" && !e.shiftKey) {
                                e.preventDefault();
                                void handleSendText();
                              }
                            }}
                            placeholder="Digite uma mensagem..."
                            maxLength={4096}
                            rows={1}
                            className="w-full text-sm text-[var(--color-text)] px-4 py-2.5 outline-none resize-none bg-transparent"
                          />
                        </div>

                        {/* Botão de Envio */}
                        <button
                          type="button"
                          onClick={handleSendText}
                          disabled={isSending || replyText.trim() === ""}
                          aria-label="Enviar mensagem"
                          title="Enviar mensagem"
                          className="w-10 h-10 rounded-full bg-[var(--color-wa-teal)] hover:brightness-95 text-white flex items-center justify-center transition shrink-0 cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed shadow-sm"
                        >
                          {isSending ? (
                            <span className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                          ) : (
                            <svg className="w-4 h-4 translate-x-0.5" viewBox="0 0 24 24" fill="currentColor">
                              <path d="M3.4 20.4l17.45-7.48a1 1 0 0 0 0-1.84L3.4 3.6a.993.993 0 0 0-1.39.91L2 9.12c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.49-.87 1l.01 4.61c0 .71.73 1.2 1.39.91z" />
                            </svg>
                          )}
                        </button>
                      </>
                    )}
                  </div>
                ) : (
                  /* Janela Fechada: Chamada para envio de template */
                  <div className="bg-[#fff9ea] border border-[#fde68a] rounded-xl p-3 flex items-center justify-between gap-3 text-[#92400e] text-xs">
                    <div>
                      <div className="font-bold text-sm">A janela de 24 horas terminou</div>
                      <p className="text-gray-600 mt-0.5">
                        Envie um template homologado pela Meta para retomar o contato com o cliente.
                      </p>
                    </div>

                    <button
                      type="button"
                      onClick={handleOpenTemplateModal}
                      className="bg-[#1d7afc] hover:bg-[#196cdb] text-white px-5 py-2.5 rounded-full font-bold transition shadow-sm cursor-pointer whitespace-nowrap"
                    >
                      Retomar com template
                    </button>
                  </div>
                )}
              </footer>
            </>
          ) : (
            <div className="flex-1 flex items-center justify-center p-8 text-center text-[var(--color-wa-time)] text-sm">
              Selecione uma conversa
            </div>
          )}
        </section>
      </div>

      {/* Modal: Nova Conversa */}
      {modalNewChatOpen && (
        <div className="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl p-6 max-w-md w-full shadow-2xl text-[var(--color-text)] space-y-4">
            <div className="flex items-center justify-between border-b pb-3">
              <h2 className="text-lg font-bold">Iniciar Nova Conversa</h2>
              <button
                type="button"
                onClick={() => setModalNewChatOpen(false)}
                className="text-gray-400 hover:text-gray-700 text-xl font-bold cursor-pointer"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleStartNewChat} className="space-y-4">
              {/* Seleção de cliente cadastrado */}
              {clientsList.length > 0 && (
                <div>
                  <label htmlFor="select-client" className="block text-xs font-bold text-gray-600 mb-1">
                    Selecionar cliente cadastrado (opcional)
                  </label>
                  <select
                    id="select-client"
                    value={newChatClientId}
                    onChange={e => {
                      const id = e.target.value;
                      setNewChatClientId(id);
                      if (id !== "") {
                        const cl = clientsList.find(c => String(c.id) === id);
                        if (cl) {
                          setNewChatPhone(cl.phone);
                          setNewChatName(cl.name);
                        }
                      }
                    }}
                    className="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-[var(--color-wa-teal)]/30"
                  >
                    <option value="">Selecione um cliente...</option>
                    {clientsList.map(cl => (
                      <option key={cl.id} value={cl.id}>
                        {cl.name} ({formatPhone(cl.phone)})
                      </option>
                    ))}
                  </select>
                </div>
              )}

              {/* Telefone manual */}
              <div>
                <label htmlFor="input-phone" className="block text-xs font-bold text-gray-600 mb-1">
                  Telefone WhatsApp (com DDD) *
                </label>
                <input
                  id="input-phone"
                  value={newChatPhone}
                  onChange={e => setNewChatPhone(e.target.value)}
                  placeholder="Ex: 21 99919-3898"
                  required
                  className="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-[var(--color-wa-teal)]/30"
                />
              </div>

              {/* Nome opcional */}
              <div>
                <label htmlFor="input-name" className="block text-xs font-bold text-gray-600 mb-1">
                  Nome do contato
                </label>
                <input
                  id="input-name"
                  value={newChatName}
                  onChange={e => setNewChatName(e.target.value)}
                  placeholder="Nome ou empresa"
                  className="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-[var(--color-wa-teal)]/30"
                />
              </div>

              <div className="flex justify-end gap-3 pt-3">
                <button
                  type="button"
                  onClick={() => setModalNewChatOpen(false)}
                  className="px-4 py-2 rounded-full border border-gray-300 text-gray-600 text-sm font-semibold hover:bg-gray-100 cursor-pointer"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  disabled={isStartingChat}
                  className="px-5 py-2 rounded-full bg-[#16a34a] hover:bg-[#15803d] text-white text-sm font-bold shadow-md cursor-pointer disabled:opacity-50"
                >
                  {isStartingChat ? "Abrindo..." : "Iniciar conversa"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal: Retomar com Template */}
      {modalTemplateOpen && selectedTemplate && (
        <div className="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl p-6 max-w-lg w-full shadow-2xl text-[var(--color-text)] space-y-4">
            <div className="flex items-center justify-between border-b pb-3">
              <div>
                <h2 className="text-lg font-bold">Retomar Conversa com Template</h2>
                <p className="text-xs text-gray-500">
                  {activeConversation?.name} · {formatPhone(activeConversation?.phone)}
                </p>
              </div>
              <button
                type="button"
                onClick={() => setModalTemplateOpen(false)}
                className="text-gray-400 hover:text-gray-700 text-xl font-bold cursor-pointer"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleSendTemplate} className="space-y-4">
              {/* Seletor de template */}
              <div>
                <label htmlFor="select-template" className="block text-xs font-bold text-gray-600 mb-1">
                  Modelo de mensagem homologado
                </label>
                <select
                  id="select-template"
                  value={selectedTemplate.name}
                  onChange={e => {
                    const tpl = templates.find(t => t.name === e.target.value);
                    if (tpl) {
                      setSelectedTemplate(tpl);
                    }
                  }}
                  className="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-[var(--color-wa-teal)]/30"
                >
                  {templates.map(tpl => (
                    <option key={tpl.name} value={tpl.name}>
                      {tpl.name} ({tpl.language})
                    </option>
                  ))}
                </select>
              </div>

              {/* Variáveis do template */}
              {selectedTemplate.params_count > 0 && (
                <div className="space-y-2 bg-gray-50 p-3 rounded-xl border border-gray-200">
                  <div className="text-xs font-bold text-gray-700">Preencha as variáveis do modelo:</div>
                  {Array.from({ length: selectedTemplate.params_count }, (_, i) => {
                    const idx = String(i + 1);
                    const label = selectedTemplate.param_labels?.[i] || `Variável {{${idx}}}`;
                    return (
                      <div key={idx}>
                        <label htmlFor={`param-${idx}`} className="block text-[11px] font-semibold text-gray-600 mb-0.5">
                          {label}
                        </label>
                        <input
                          id={`param-${idx}`}
                          value={templateValues[idx] ?? ""}
                          onChange={e =>
                            setTemplateValues(prev => ({
                              ...prev,
                              [idx]: e.target.value,
                            }))
                          }
                          required
                          className="w-full bg-white border border-gray-200 rounded-lg px-3 py-1.5 text-sm outline-none focus:ring-2 focus:ring-[var(--color-wa-teal)]/30"
                        />
                      </div>
                    );
                  })}
                </div>
              )}

              {/* Prévia do texto */}
              <div className="bg-[#e4f8ed] p-3 rounded-xl border border-[#aee4c9] text-xs text-[#0e7a4c]">
                <div className="font-bold mb-1">Prévia no WhatsApp:</div>
                <p className="whitespace-pre-wrap">
                  {selectedTemplate.body_text.replace(/\{\{(\d+)\}\}/g, (_, num) => templateValues[num] || `[${num}]`)}
                </p>
              </div>

              <div className="flex justify-end gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setModalTemplateOpen(false)}
                  className="px-4 py-2 rounded-full border border-gray-300 text-gray-600 text-sm font-semibold hover:bg-gray-100 cursor-pointer"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  disabled={isSendingTemplate}
                  className="px-5 py-2 rounded-full bg-[#1d7afc] hover:bg-[#196cdb] text-white text-sm font-bold shadow-md cursor-pointer disabled:opacity-50"
                >
                  {isSendingTemplate ? "Enviando..." : "Enviar Template"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}

/** Faixa informativa de status da janela de atendimento da Meta */
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
  const isAudio = (message.type ?? "").toLowerCase() === "audio" || (message.body ?? "").includes("[áudio]");

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

        {/* Player de áudio caso a mensagem seja áudio */}
        {isAudio && message.media_url ? (
          <div className="py-1">
            <audio controls src={message.media_url} className="max-w-full h-10" preload="metadata" />
          </div>
        ) : (
          <p className="whitespace-pre-wrap break-words text-[14.5px] leading-snug">
            {message.body || `[${message.type ?? "sem conteúdo"}]`}
          </p>
        )}

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
