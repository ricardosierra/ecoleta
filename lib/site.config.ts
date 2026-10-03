/**
 * Configuração central da Ecoleva.
 *
 * Placeholders devem ser substituídos antes do go-live:
 * - WHATSAPP_NUMBER, INSTAGRAM_URL, LINKEDIN_URL
 * - CNPJ, ENDERECO
 * - CONTACT_TO_EMAIL (também via env)
 *
 * Toda variável NEXT_PUBLIC_* passa por uma verificação de formato: vazia ou
 * malformada (o valor de exemplo do .env.example, um número com letras, um link
 * sem protocolo) conta como AUSENTE e cai no padrão do código. Antes, o número
 * de exemplo `55XXXXXXXXXXX` vencia o padrão e o link do WhatsApp virava
 * `https://wa.me/55`.
 *
 * As referências a `process.env.NEXT_PUBLIC_*` abaixo são literais de propósito:
 * o Next só embute no bundle do navegador o que aparece escrito assim.
 */

/** Todos os dígitos iguais (00000000000, 11111111111): é valor de exemplo, não número real. */
const isRepeatedDigit = (digits: string) => /^(\d)\1+$/.test(digits);

/**
 * Número de WhatsApp em formato internacional, só dígitos, ou null quando não
 * parece um número. Aceita a formatação comum (+55 (21) 99152-9383) e devolve só
 * os dígitos; recusa letras, tamanho fora de 10 a 15 dígitos (limite do E.164) e
 * sequência de um dígito só.
 */
export function normalizeWhatsappNumber(raw: string | undefined): string | null {
  const compact = (raw ?? "").trim().replace(/[\s().+-]/g, "");
  if (!/^\d{10,15}$/.test(compact) || isRepeatedDigit(compact)) return null;

  return compact;
}

/** CNPJ no formato 00.000.000/0000-00 (aceita também 14 dígitos), ou null. */
export function normalizeCnpj(raw: string | undefined): string | null {
  const text = (raw ?? "").trim();
  if (!/^(\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}|\d{14})$/.test(text)) return null;

  const digits = text.replace(/\D/g, "");
  if (isRepeatedDigit(digits)) return null;

  return digits.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, "$1.$2.$3/$4-$5");
}

/**
 * URL http(s) completa, ou null. Recusa o que `new URL` não entende, esquemas
 * como javascript: (iria direto para um href) e o marcador de exemplo XXXX.
 */
export function normalizeUrl(raw: string | undefined): string | null {
  const text = (raw ?? "").trim();
  if (text === "" || /X{4,}/.test(text)) return null;

  try {
    const { protocol } = new URL(text);

    return protocol === "http:" || protocol === "https:" ? text : null;
  } catch {
    return null;
  }
}

/** E-mail com cara de e-mail, ou null. */
export function normalizeEmail(raw: string | undefined): string | null {
  const text = (raw ?? "").trim();

  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(text) && !/X{4,}/.test(text) ? text : null;
}

/** Telefone para exibição: dígitos e a pontuação usual, 8 a 15 dígitos, ou null. */
export function normalizePhoneDisplay(raw: string | undefined): string | null {
  const text = (raw ?? "").trim();
  if (!/^[\d\s().+-]+$/.test(text)) return null;

  const digits = text.replace(/\D/g, "");

  return digits.length >= 8 && digits.length <= 15 && !isRepeatedDigit(digits) ? text : null;
}

/**
 * Número de WhatsApp (só dígitos) para exibir: `5521991529383` vira
 * `(21) 99152-9383`. Número brasileiro com DDI 55 ganha a máscara nacional; os
 * demais saem com `+` na frente. É a mesma fonte do link wa.me, então texto e
 * link nunca divergem.
 */
export function formatWhatsappDisplay(digits: string): string {
  const br = /^55(\d{2})(\d{4,5})(\d{4})$/.exec(digits);

  return br ? `(${br[1]}) ${br[2]}-${br[3]}` : `+${digits}`;
}

const DEFAULT_WHATSAPP_NUMBER = "5521991529383";

const whatsappNumber =
  normalizeWhatsappNumber(process.env.NEXT_PUBLIC_WHATSAPP_NUMBER) ?? DEFAULT_WHATSAPP_NUMBER;
const whatsappDisplay = formatWhatsappDisplay(whatsappNumber);

export const siteConfig = {
  name: "Ecoleva",
  legalName: "Ecoleva Soluções Ambientais",
  technicalPartners: [
    { name: "Rica Soluções", url: "https://ricasolucoes.com.br" },
    { name: "Sierra Tecnologia", url: "https://sierratecnologia.com.br" },
  ],
  tagline: "Gestão de resíduos com rastreabilidade e impacto ESG",
  description:
    "Gestão completa de resíduos para empresas, eventos e indústrias. Rastreabilidade, conformidade ambiental, MTR, CDF, relatórios ESG e redução do envio ao aterro.",
  url: normalizeUrl(process.env.NEXT_PUBLIC_SITE_URL) ?? "https://www.ecolevaeco.com",
  locale: "pt_BR",
  keywords: [
    "gestão de resíduos",
    "resíduos sólidos",
    "ESG",
    "rastreabilidade ambiental",
    "MTR",
    "CDF",
    "PGRS",
    "conformidade ambiental",
    "coleta de resíduos",
    "Ecoleva",
    "Econformidade",
  ],

  contact: {
    /** E-mail de destino do formulário (também sobrescrevível via env CONTACT_TO_EMAIL). */
    email:
      normalizeEmail(process.env.NEXT_PUBLIC_CONTACT_EMAIL) ??
      "diretoria@econformidade.com.br",
    /** Número de WhatsApp em formato internacional sem caracteres especiais. */
    whatsappNumber,
    /** O mesmo número, formatado para mostrar na tela (rodapé, contato). */
    whatsappDisplay,
    whatsappMessage: "Olá! Gostaria de falar com um especialista da Ecoleva.",
  },

  social: {
    instagram:
      normalizeUrl(process.env.NEXT_PUBLIC_INSTAGRAM_URL) ??
      "https://instagram.com/ecoleva.eco",
    linkedin:
      normalizeUrl(process.env.NEXT_PUBLIC_LINKEDIN_URL) ??
      "https://linkedin.com/company/econformidade",
  },

  company: {
    cnpj: normalizeCnpj(process.env.NEXT_PUBLIC_CNPJ) ?? "57.772.812/0001-72",
    address: process.env.NEXT_PUBLIC_ADDRESS?.trim() || "Endereço a definir",
    /** Sem variável própria válida, acompanha o WhatsApp (o JSON-LD do site usa este campo). */
    phoneComercial:
      normalizePhoneDisplay(process.env.NEXT_PUBLIC_PHONE_COMERCIAL) ?? whatsappDisplay,
    areaServed: "Brasil",
  },

  legal: {
    /**
     * Data da última revisão do TEXTO da política de privacidade (AAAA-MM-DD),
     * lida pela página e pelo sitemap. É uma constante de propósito: com
     * new Date() a data mudava a cada deploy e afirmava uma revisão que nunca
     * aconteceu. Atualize junto com qualquer mudança no texto da política.
     */
    privacyPolicyUpdatedAt: "2026-09-03",
  },

  nav: [
    { label: "Início", href: "/" },
    { label: "Soluções", href: "/solucoes" },
    { label: "ESG", href: "/esg" },
    { label: "Sobre", href: "/sobre" },
    { label: "Contato", href: "/contato" },
  ],
} as const;

export const whatsappLink = (() => {
  const number = siteConfig.contact.whatsappNumber.replace(/\D/g, "");
  const text = encodeURIComponent(siteConfig.contact.whatsappMessage);
  return `https://wa.me/${number}?text=${text}`;
})();
