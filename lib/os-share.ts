/**
 * Encaminhamento de Ordem de Serviço — módulo puro, sem React e sem rede.
 *
 * O e-mail é montado no servidor (`public/api/os/send.php`, que reaproveita
 * `public/api/os/os_lib.php`). O WhatsApp não tem API oficial neste projeto:
 * o que existe é o link `wa.me`, que o navegador abre com o texto já escrito
 * para o operador só apertar enviar. Montar esse texto é o que este módulo faz.
 *
 * Fica fora do componente porque é a parte que dá para testar: a tela de OS já
 * passa das 250 linhas e a formatação de data tem armadilha (ver `formatOsDate`).
 */

import type { WhatsAppWindow } from "@/lib/whatsapp";

export type ServiceOrder = {
  id: number;
  client_id?: number;
  client_name: string;
  client_email?: string | null;
  client_whatsapp?: string | null;
  weight?: string | null;
  collection_date?: string | null;
  bags_count?: string | number | null;
  containers_count?: string | number | null;
  responsible?: string | null;
  signature_text?: string | null;
  /** Último envio por e-mail (`os/send.php`). */
  sent_at?: string | null;
  sent_to?: string | null;
  /** Último disparo pelo WhatsApp do robô (`os/whatsapp.php`). */
  whatsapp_sent_at?: string | null;
  whatsapp_sent_to?: string | null;
  created_at?: string | null;
  /** Link público com token, montado por `osPresent()` no PHP. */
  share_url?: string | null;
  /**
   * Janela de 24h do WhatsApp deste cliente, de `whatsapp_conversations`.
   * `null`/ausente quando o cliente nunca escreveu para o nosso número.
   */
  whatsapp_window?: WhatsAppWindow | null;
};

/** Número exibido do documento: 42 → "00042". O PHP usa o mesmo formato. */
export function osNumber(id: number): string {
  return String(id).padStart(5, "0");
}

/**
 * Data ISO do banco (`YYYY-MM-DD`) em `dd/mm/aaaa`.
 *
 * A leitura por regex é de propósito. `new Date("2026-09-03")` é interpretado
 * como meia-noite **UTC**, e `toLocaleDateString("pt-BR")` de volta em
 * America/Sao_Paulo (UTC-3) devolve 02/09/2026 — a coleta aparecia um dia antes
 * do que foi digitado, o dia inteiro, para todo o Brasil.
 */
export function formatOsDate(value?: string | null): string {
  const iso = (value ?? "").trim();
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);

  // `0000-00-00` é a data zero do MySQL, gravada por coluna DATE que recebeu
  // string vazia em modo não estrito. É ausência de data, não 00/00/0000.
  if (!match || match[1] === "0000") {
    return "—";
  }

  return `${match[3]}/${match[2]}/${match[1]}`;
}

/** Data e hora de um `TIMESTAMP` do MySQL (`YYYY-MM-DD HH:MM:SS`). */
export function formatOsDateTime(value?: string | null): string {
  const raw = (value ?? "").trim();
  const match = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/.exec(raw);

  return match ? `${match[3]}/${match[2]}/${match[1]} ${match[4]}:${match[5]}` : "—";
}

/** Campo opcional: vazio, nulo e `undefined` viram travessão. */
export function osFieldValue(value?: string | number | null): string {
  const text = value === null || value === undefined ? "" : String(value).trim();

  return text === "" ? "—" : text;
}

/**
 * Texto que vai no WhatsApp. Sem o link quando a OS ainda não tem token —
 * melhor mandar os dados do que uma mensagem com um "undefined" no fim.
 */
export function osShareMessage(os: ServiceOrder): string {
  const linhas = [
    `*Ordem de Serviço Nº ${osNumber(os.id)}* — Ecoleva`,
    "",
    `Cliente: ${osFieldValue(os.client_name)}`,
    `Data da coleta: ${formatOsDate(os.collection_date)}`,
    `Pesagem: ${osFieldValue(os.weight)}`,
    `Qtd. sacos: ${osFieldValue(os.bags_count)}`,
    `Qtd. contêineres: ${osFieldValue(os.containers_count)}`,
    `Responsável pela coleta: ${osFieldValue(os.responsible)}`,
  ];

  if (os.share_url) {
    linhas.push("", `Abrir e imprimir: ${os.share_url}`);
  }

  return linhas.join("\n");
}

/**
 * Link `wa.me` com a mensagem pronta.
 *
 * Sem número cadastrado o link sai sem destinatário: o WhatsApp abre o seletor
 * de contatos com o texto já preenchido, que ainda encaminha a OS — melhor do
 * que desabilitar o botão para todo cliente sem telefone no cadastro.
 */
export function osWhatsAppLink(os: ServiceOrder): string {
  const digits = (os.client_whatsapp ?? "").replace(/\D/g, "");
  const texto = encodeURIComponent(osShareMessage(os));

  return digits === "" ? `https://wa.me/?text=${texto}` : `https://wa.me/${digits}?text=${texto}`;
}
