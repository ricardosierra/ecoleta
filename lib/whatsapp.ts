/**
 * Painel de WhatsApp — módulo puro, sem React e sem rede.
 *
 * Guarda a leitura da janela de 24 horas e a formatação que a tela de conversas
 * e o botão do robô na OS precisam. Está fora dos componentes pelo mesmo motivo
 * de `lib/authz.ts`: é a parte que dá para testar sem montar tela.
 *
 * Os instantes chegam da API em ISO-8601 UTC (`2026-09-03T14:22:00Z`) porque as
 * colunas guardam UTC — ver o comentário da migration 015. Quem converte para o
 * fuso de quem está olhando é o navegador, aqui embaixo.
 */

/** Estado da janela de atendimento, como `waWindowState()` no PHP devolve. */
export type WhatsAppWindow = {
  open: boolean;
  expires_at: string | null;
  minutes_left: number | null;
};

export type WhatsAppConversation = {
  id: number;
  phone: string;
  name: string;
  profile_name?: string | null;
  client_id?: number | null;
  client_name?: string | null;
  status: string;
  unread_count: number;
  last_message_at: string | null;
  last_message_preview?: string | null;
  last_message_direction?: string | null;
  window: WhatsAppWindow;
};

export type WhatsAppMessage = {
  id: number;
  direction: "incoming" | "outgoing" | string;
  type?: string | null;
  status?: string | null;
  body?: string | null;
  error_message?: string | null;
  message_at: string | null;
  service_order_id?: number | null;
};

/** Como a faixa no topo da conversa e o botão do robô devem se apresentar. */
export type WindowTone = "open" | "soon" | "closed";

/**
 * Falta menos de duas horas para fechar? A faixa fica âmbar, como no painel da
 * Banlek — é o aviso de que a resposta gratuita tem prazo.
 */
const SOON_THRESHOLD_MINUTES = 120;

export function windowTone(window?: WhatsAppWindow | null): WindowTone {
  if (!window?.open) {
    return "closed";
  }

  const left = window.minutes_left;

  return left !== null && left <= SOON_THRESHOLD_MINUTES ? "soon" : "open";
}

/** Tempo restante em linguagem de gente: "38 min", "5 h", "1 d 3 h". */
export function humanMinutes(minutes?: number | null): string {
  if (minutes === null || minutes === undefined || minutes <= 0) {
    return "expirada";
  }
  if (minutes < 60) {
    return `${minutes} min`;
  }

  const horas = Math.floor(minutes / 60);
  if (horas < 24) {
    const resto = minutes % 60;
    return resto === 0 ? `${horas} h` : `${horas} h ${resto} min`;
  }

  const dias = Math.floor(horas / 24);
  const restoHoras = horas % 24;

  return restoHoras === 0 ? `${dias} d` : `${dias} d ${restoHoras} h`;
}

/**
 * O texto do tooltip do botão do robô na OS.
 *
 * Dentro da janela a mensagem é texto livre, que a Meta entrega sem cobrar; fora
 * dela só passa template aprovado, que é tarifado. É a diferença que decide se
 * vale apertar o botão agora ou esperar o cliente responder.
 */
export function windowTooltip(window?: WhatsAppWindow | null): string {
  if (!window) {
    return "Este cliente nunca escreveu para o nosso WhatsApp — fora da janela de 24 horas, o envio exige template aprovado e é cobrado.";
  }

  if (window.open) {
    return `Dentro da janela de 24 horas (fecha em ${humanMinutes(
      window.minutes_left
    )}) — o envio é gratuito, a Meta não cobra.`;
  }

  return "Fora da janela de 24 horas — o envio exige template aprovado e é cobrado pela Meta.";
}

/** Rótulo curto da faixa no topo da conversa. */
export function windowLabel(window?: WhatsAppWindow | null): string {
  if (!window?.open) {
    return "Janela fechada — só por template";
  }

  return `Janela aberta — fecha em ${humanMinutes(window.minutes_left)}`;
}

/** Hora local no formato 24h, para a legenda da bolha. */
export function formatMessageTime(iso?: string | null): string {
  const date = parseIso(iso);

  return date === null
    ? ""
    : date.toLocaleTimeString("pt-BR", { hour: "2-digit", minute: "2-digit" });
}

/** Separador de dia dentro da conversa: "Hoje", "Ontem" ou a data. */
export function formatDayLabel(iso?: string | null, today: Date = new Date()): string {
  const date = parseIso(iso);
  if (date === null) {
    return "";
  }

  const dias = diffInDays(date, today);
  if (dias === 0) return "Hoje";
  if (dias === 1) return "Ontem";

  return date.toLocaleDateString("pt-BR");
}

/**
 * Carimbo da lista de conversas: hora quando é de hoje, "Ontem", e a data
 * depois disso — o mesmo escalonamento do aplicativo do WhatsApp.
 */
export function formatConversationStamp(iso?: string | null, today: Date = new Date()): string {
  const date = parseIso(iso);
  if (date === null) {
    return "";
  }

  const dias = diffInDays(date, today);
  if (dias === 0) return formatMessageTime(iso);
  if (dias === 1) return "Ontem";

  return date.toLocaleDateString("pt-BR", { day: "2-digit", month: "2-digit" });
}

/** Agrupa as mensagens por dia, preservando a ordem cronológica que veio. */
export function groupMessagesByDay(
  messages: WhatsAppMessage[],
  today: Date = new Date()
): { day: string; messages: WhatsAppMessage[] }[] {
  const grupos: { day: string; messages: WhatsAppMessage[] }[] = [];

  for (const message of messages) {
    const day = formatDayLabel(message.message_at, today);
    const ultimo = grupos[grupos.length - 1];

    if (ultimo && ultimo.day === day) {
      ultimo.messages.push(message);
    } else {
      grupos.push({ day, messages: [message] });
    }
  }

  return grupos;
}

/** Iniciais do avatar. Cai nos dois últimos dígitos quando não há nome. */
export function initials(name?: string | null, phone?: string | null): string {
  const limpo = (name ?? "").trim();

  if (limpo !== "") {
    const partes = limpo.split(/\s+/).filter(Boolean);
    const primeira = partes[0]?.[0] ?? "";
    const ultima = partes.length > 1 ? partes[partes.length - 1][0] : "";

    return (primeira + ultima).toUpperCase();
  }

  const digitos = (phone ?? "").replace(/\D/g, "");

  return digitos === "" ? "?" : digitos.slice(-2);
}

/** `5521999887766` → `+55 (21) 99988-7766`. Devolve como veio se não encaixar. */
export function formatPhone(phone?: string | null): string {
  const digitos = (phone ?? "").replace(/\D/g, "");

  const match = /^55(\d{2})(\d{4,5})(\d{4})$/.exec(digitos);
  if (match) {
    return `+55 (${match[1]}) ${match[2]}-${match[3]}`;
  }

  return digitos === "" ? "" : `+${digitos}`;
}

/**
 * Símbolo de entrega de uma mensagem nossa, no vocabulário do WhatsApp:
 * um traço para aceita, um tique para enviada, dois para entregue e lida.
 */
export function deliveryMark(status?: string | null): string {
  switch ((status ?? "").toLowerCase()) {
    case "sent":
      return "✓";
    case "delivered":
    case "read":
      return "✓✓";
    case "failed":
      return "!";
    default:
      return "·";
  }
}

function parseIso(iso?: string | null): Date | null {
  const value = (iso ?? "").trim();
  if (value === "") {
    return null;
  }

  const date = new Date(value);

  return Number.isNaN(date.getTime()) ? null : date;
}

/** Diferença em dias de calendário local — não em blocos de 24 horas. */
function diffInDays(date: Date, today: Date): number {
  const a = new Date(date.getFullYear(), date.getMonth(), date.getDate());
  const b = new Date(today.getFullYear(), today.getMonth(), today.getDate());

  return Math.round((b.getTime() - a.getTime()) / 86400000);
}
