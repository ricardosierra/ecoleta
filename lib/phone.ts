/**
 * Normaliza um número de telefone/WhatsApp para o formato numérico padrão com DDI (55).
 * Trata variações de formatação, DDD com 0 à esquerda e DDI explícito.
 *
 * Exemplo:
 * - "+55 (021) 99988-7766" -> "5521999887766"
 * - "(21) 99988-7766"     -> "5521999887766"
 * - "+55 21 99988-7766"   -> "5521999887766"
 * - "021999887766"        -> "5521999887766"
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
