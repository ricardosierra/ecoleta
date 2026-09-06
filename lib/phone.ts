/**
 * DDD assumido quando o número vem sem ele.
 *
 * A Ecoleva atende a partir de Rio Bonito - RJ e o cadastro é preenchido por
 * quem já está lá; um número digitado como "99988-7766" é um número do 21. Sem
 * este padrão, os 9 dígitos seguiam para o banco como "999887766" — que a Meta
 * recusa e que nenhum `wa.me` abre.
 */
export const DEFAULT_AREA_CODE = "21";

/**
 * Normaliza um número de telefone/WhatsApp para o formato numérico padrão com DDI (55).
 * Trata variações de formatação, DDD com 0 à esquerda e DDI explícito.
 *
 * Exemplo:
 * - "+55 (021) 99988-7766" -> "5521999887766"
 * - "(21) 99988-7766"     -> "5521999887766"
 * - "+55 21 99988-7766"   -> "5521999887766"
 * - "021999887766"        -> "5521999887766"
 * - "99988-7766"          -> "5521999887766"  (DDD padrão)
 */
export function normalizePhone(phone?: string | null): string {
  if (!phone) {
    return "";
  }

  // Remove caracteres não numéricos
  let digits = phone.replace(/\D/g, "");

  if (!digits) {
    return "";
  }

  // R1: Se começar com 55, tiver 14 dígitos e o terceiro dígito for '0', remove esse '0' (550XX9XXXXXXXX -> 55XX9XXXXXXXX)
  if (digits.startsWith("55") && digits.length === 14 && digits[2] === "0") {
    digits = "55" + digits.slice(3);
  } else if (digits.startsWith("55") && digits.length === 13 && digits[2] === "0") {
    // Trata número fixo com DDI e 0 no DDD: 550XX8XXXXXXX -> 55XX8XXXXXXX (12 dígitos)
    digits = "55" + digits.slice(3);
  } else if (!digits.startsWith("55")) {
    // Se começar com 0 sem DDI (ex: 021999887766 -> 21999887766)
    if (digits.startsWith("0")) {
      digits = digits.slice(1);
    }
    // 8 (fixo) ou 9 (celular) dígitos é número sem DDD: completa com o padrão.
    if (digits.length === 8 || digits.length === 9) {
      digits = DEFAULT_AREA_CODE + digits;
    }
    // Se tiver 10 ou 11 dígitos (DDD + número), adiciona DDI 55
    if (digits.length === 10 || digits.length === 11) {
      digits = "55" + digits;
    }
  }

  return digits;
}

export const normalizeWhatsApp = normalizePhone;
export const normalizePhoneNumber = normalizePhone;
export default normalizePhone;
