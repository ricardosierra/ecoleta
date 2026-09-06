<?php
declare(strict_types=1);

/**
 * DDD assumido quando o número vem sem ele. Espelho de `DEFAULT_AREA_CODE` em
 * `lib/phone.ts` — mexeu em um, mexa no outro.
 *
 * A Ecoleva atende a partir de Rio Bonito - RJ e o cadastro é preenchido por
 * quem já está lá; um número digitado como "99988-7766" é um número do 21. Sem
 * este padrão, os 9 dígitos seguiam para o banco como "999887766" — que a Meta
 * recusa e que nenhum `wa.me` abre.
 */
const PHONE_DEFAULT_AREA_CODE = '21';

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
function normalizePhone(?string $phone): string
{
    if ($phone === null) {
        return '';
    }

    // Remove caracteres não numéricos
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    if ($digits === '') {
        return '';
    }

    // R1: Se começar com 55, tiver 14 dígitos e o terceiro dígito for '0', remove esse '0' (550XX9XXXXXXXX -> 55XX9XXXXXXXX)
    if (str_starts_with($digits, '55') && strlen($digits) === 14 && $digits[2] === '0') {
        $digits = '55' . substr($digits, 3);
    } elseif (str_starts_with($digits, '55') && strlen($digits) === 13 && $digits[2] === '0') {
        // Trata número fixo com DDI e 0 no DDD: 550XX8XXXXXXX -> 55XX8XXXXXXX (12 dígitos)
        $digits = '55' . substr($digits, 3);
    } elseif (!str_starts_with($digits, '55')) {
        // Se começar com 0 sem DDI (ex: 021999887766 -> 21999887766)
        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        // 8 (fixo) ou 9 (celular) dígitos é número sem DDD: completa com o padrão.
        if (strlen($digits) === 8 || strlen($digits) === 9) {
            $digits = PHONE_DEFAULT_AREA_CODE . $digits;
        }
        // Se tiver 10 ou 11 dígitos (DDD + número), adiciona DDI 55
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $digits = '55' . $digits;
        }
    }

    return $digits;
}

function normalizeWhatsApp(?string $phone): string
{
    return normalizePhone($phone);
}

function apiNormalizePhone(?string $phone): string
{
    return normalizePhone($phone);
}
