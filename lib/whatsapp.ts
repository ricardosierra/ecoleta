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
  media_url?: string | null;
};

export type WhatsAppClientOption = {
  id: number;
  name: string;
  phone: string;
};

export type WhatsAppTemplate = {
  name: string;
  language: string;
  category?: string;
  /** Status real na Meta (`APPROVED`, `PENDING`...) ou `UNVERIFIED` quando a Meta não respondeu. */
  status?: string;
  body_text: string;
  params_count: number;
  /** Rótulo de cada variável, na ordem. Só vem para templates que o servidor conhece. */
  param_labels?: string[];
  /** O que cada variável é (`client_name`, `os_number`...). Só o nome do cliente é pré-preenchido. */
  param_kinds?: string[];
};

/** Resposta de `api/whatsapp/templates.php`. */
export type WhatsAppTemplatesResponse = {
  ok?: boolean;
  templates?: WhatsAppTemplate[];
  /** `meta` quando veio da Meta; `config` quando a Meta não pôde ser consultada. */
  source?: string;
  /** Motivo, em português, de a Meta não ter sido consultada. */
  error?: string | null;
  /** Texto original da Meta, para quem for investigar. */
  error_detail?: string | null;
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

/** Formata milissegundos para "00:05", "01:23", etc. para o gravador de áudio. */
export function formatAudioDuration(ms: number): string {
  const totalSeconds = Math.max(0, Math.floor(ms / 1000));
  const minutes = String(Math.floor(totalSeconds / 60)).padStart(2, "0");
  const seconds = String(totalSeconds % 60).padStart(2, "0");

  return `${minutes}:${seconds}`;
}

/** Identifica categoria de mídia pelo MIME type. */
export function mimeTypeToCategory(mimeType: string): "image" | "audio" | "document" | "video" {
  const lower = mimeType.toLowerCase();
  if (lower.startsWith("image/")) return "image";
  if (lower.startsWith("audio/")) return "audio";
  if (lower.startsWith("video/")) return "video";
  return "document";
}

// ── Templates: o que a tela pode preencher e enviar ──────────────────────────

/** A variável `index` (0-based) do template é o nome do cliente? */
function isClientNameParam(template: WhatsAppTemplate, index: number): boolean {
  const kinds = template.param_kinds;

  if (kinds && kinds.length > 0) {
    return kinds[index] === "client_name";
  }

  // Servidor antigo, sem os tipos: o rótulo ainda diz quando é o nome.
  return /^nome\b/i.test((template.param_labels?.[index] ?? "").trim());
}

/**
 * Valores iniciais das variáveis do template na conversa aberta.
 *
 * Só o que se sabe com segurança: o nome do cliente, no parâmetro que é o nome.
 * Número da OS, valor, vencimento e link ficam VAZIOS, porque um chute ("00001",
 * a URL do site) segue para o cliente como se fosse o dado real, numa mensagem
 * que a Meta cobra. Quem chama troca de template reconstruindo o objeto, nunca
 * mesclando com o anterior.
 */
export function initialTemplateValues(
  template: WhatsAppTemplate | null | undefined,
  conversation: Pick<WhatsAppConversation, "name"> | null | undefined
): Record<string, string> {
  const valores: Record<string, string> = {};
  const nome = (conversation?.name ?? "").trim();

  if (!template || nome === "") {
    return valores;
  }

  for (let i = 0; i < template.params_count; i++) {
    if (isClientNameParam(template, i)) {
      valores[String(i + 1)] = nome;
    }
  }

  return valores;
}

/** Variáveis (1, 2, 3...) que ainda estão em branco. Espaço não conta como preenchido. */
export function missingTemplateParams(
  template: Pick<WhatsAppTemplate, "params_count">,
  values: Record<string, string>
): number[] {
  const faltando: number[] = [];

  for (let i = 1; i <= template.params_count; i++) {
    if ((values[String(i)] ?? "").trim() === "") {
      faltando.push(i);
    }
  }

  return faltando;
}

/**
 * O que a tela faz com o template: `ok` envia, `unverified` envia com aviso (a
 * Meta não respondeu, então a aprovação é desconhecida) e `blocked` não envia
 * (em análise, rejeitado, pausado...: a Meta recusaria).
 */
export function templateSendability(
  template: Pick<WhatsAppTemplate, "status"> | null | undefined
): "ok" | "unverified" | "blocked" {
  const status = (template?.status ?? "").toUpperCase();

  if (status === "APPROVED") return "ok";
  if (status === "UNVERIFIED") return "unverified";

  return "blocked";
}

/** O status do template em português, para o seletor e a faixa do modal. */
export function templateStatusLabel(status?: string | null): string {
  const valor = (status ?? "").trim().toUpperCase();

  switch (valor) {
    case "":
    case "UNKNOWN":
      return "Situação desconhecida";
    case "APPROVED":
      return "Aprovado";
    case "PENDING":
      return "Em análise na Meta";
    case "IN_APPEAL":
      return "Em recurso na Meta";
    case "REJECTED":
      return "Rejeitado pela Meta";
    case "PAUSED":
      return "Pausado pela Meta";
    case "DISABLED":
      return "Desativado pela Meta";
    case "UNVERIFIED":
      return "Não verificado na Meta";
    default:
      return valor;
  }
}

// ── Busca e gravação de áudio ────────────────────────────────────────────────

/**
 * O termo da busca é um telefone (dígitos e a pontuação de máscara), e não um
 * nome com número? "Posto 3" não é: tratar o 3 como telefone casava com toda
 * conversa cujo número tivesse um 3.
 */
export function looksLikePhoneSearch(term: string): boolean {
  const limpo = term.trim();

  return /^[\d\s().+-]+$/.test(limpo) && /\d/.test(limpo);
}

/** Minúsculas e sem acento: o mesmo que a busca do servidor (collation do MySQL) enxerga. */
function normalizeSearchText(texto: string): string {
  return texto
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .trim();
}

/**
 * A conversa casa com o termo digitado na busca?
 *
 * Procura no nome, no perfil do WhatsApp, no nome do cliente e na prévia, sem
 * ligar para caixa nem acento; no telefone só quando o termo é um telefone. É a
 * mesma regra de `api/whatsapp/conversations.php?q=`, que alcança as conversas
 * fora das 300 mais recentes: aqui ela dá a resposta imediata, enquanto o
 * servidor confirma.
 */
export function matchesConversationSearch(
  conversation: Pick<
    WhatsAppConversation,
    "name" | "profile_name" | "client_name" | "phone" | "last_message_preview"
  >,
  term: string
): boolean {
  const termo = normalizeSearchText(term);

  if (termo === "") {
    return true;
  }

  const textos = [
    conversation.name,
    conversation.profile_name,
    conversation.client_name,
    conversation.last_message_preview,
  ];

  if (textos.some(texto => normalizeSearchText(texto ?? "").includes(termo))) {
    return true;
  }

  if (looksLikePhoneSearch(term)) {
    const digitos = term.replace(/\D/g, "");

    return digitos !== "" && conversation.phone.includes(digitos);
  }

  return false;
}

/**
 * Formatos que o gravador do navegador pode usar e a Meta aceita, em ordem de
 * preferência. WebM, que é o que o Chrome grava por padrão, a Meta recusa: nele
 * o envio sempre falhava depois de a pessoa já ter gravado.
 */
const WHATSAPP_RECORDER_MIME_TYPES = ["audio/ogg;codecs=opus", "audio/mp4"];

/**
 * O primeiro formato de gravação aceito pelo WhatsApp que o navegador suporta,
 * ou `undefined` quando não há nenhum (a tela avisa antes de gravar).
 */
export function recorderMimeType(isSupported: (mimeType: string) => boolean): string | undefined {
  return WHATSAPP_RECORDER_MIME_TYPES.find(isSupported);
}
