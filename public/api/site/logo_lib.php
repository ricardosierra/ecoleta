<?php
declare(strict_types=1);

/**
 * Upload e tratamento das logos de empresas parceiras.
 *
 * O arquivo enviado nunca é movido para o webroot como veio: a imagem é
 * decodificada pelo GD e re-encodada em um PNG novo. Isso normaliza o formato
 * (JPEG/WebP viram PNG), limita as dimensões ao que o carrossel usa, descarta
 * metadados e elimina qualquer payload embutido no arquivo original.
 *
 * Os PNGs finais moram em uploads/logos/ dentro do webroot — fora de out/, que
 * é o que o deploy FTP sobrescreve, então sobrevivem a qualquer publicação.
 */

const ECOLETA_LOGO_MAX_BYTES = 4 * 1024 * 1024;
const ECOLETA_LOGO_MAX_WIDTH = 600;
const ECOLETA_LOGO_MAX_HEIGHT = 360;
const ECOLETA_LOGO_PUBLIC_PREFIX = '/uploads/logos/';

/** Diretório físico dos uploads. ECOLETA_UPLOADS_DIR existe para os testes. */
function ecoletaLogoUploadsDir(): string
{
    $override = getenv('ECOLETA_UPLOADS_DIR');
    if (is_string($override) && $override !== '') {
        return rtrim($override, '/');
    }

    // public/api/site → public (webroot em produção).
    return dirname(__DIR__, 2) . '/uploads/logos';
}

/**
 * Valida a entrada de $_FILES sem confiar no mime declarado pelo navegador:
 * o tipo sai dos bytes, via getimagesize(). Devolve a mensagem de erro para o
 * usuário, ou null quando o arquivo serve.
 *
 * @param array{error?: int, size?: int, tmp_name?: string} $file
 */
function ecoletaLogoValidateUpload(array $file): ?string
{
    if (isset($file['error']) && is_array($file['error'])) {
        return 'Upload inválido — formato não suportado.';
    }

    $errCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
        return 'Imagem muito grande — o limite é 4 MB.';
    }
    if ($errCode !== UPLOAD_ERR_OK) {
        return 'Falha no envio da imagem. Tente de novo.';
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > ECOLETA_LOGO_MAX_BYTES) {
        return 'Imagem muito grande — o limite é 4 MB.';
    }

    $info = @getimagesize((string) ($file['tmp_name'] ?? ''));
    if ($info === false) {
        return 'O arquivo enviado não é uma imagem válida.';
    }

    if (!in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
        return 'Formato não suportado — envie PNG, JPEG ou WebP.';
    }

    return null;
}

/**
 * No SAPI de verdade só um upload real passa por is_uploaded_file(). O harness
 * de testes injeta $_FILES à mão em um processo CLI, onde essa marca não
 * existe — lá basta o arquivo existir.
 */
function ecoletaLogoIsUploadedFile(string $tmpPath): bool
{
    if (getenv('ECOLETA_TEST_CONTEXT') !== false) {
        return is_file($tmpPath);
    }

    return is_uploaded_file($tmpPath);
}

/**
 * Nome de empresa → miolo do nome de arquivo (ascii, minúsculo, hífens).
 * A tabela cobre os acentos do português na mão — iconv//TRANSLIT muda de
 * resultado conforme a libiconv da máquina, e o nome do arquivo precisa ser
 * o mesmo em qualquer servidor.
 */
function ecoletaLogoSlug(string $name): string
{
    static $acentos = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
        'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a',
        'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
        'Ó' => 'o', 'Ò' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o',
        'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
        'Ç' => 'c', 'Ñ' => 'n',
    ];

    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', strtr($name, $acentos)) ?? '');
    $slug = trim($slug, '-');

    return $slug !== '' ? substr($slug, 0, 40) : 'logo';
}

/**
 * Re-encoda a imagem como PNG com transparência, reduzida (nunca ampliada)
 * para caber em 600×360, e grava em $destDir com nome derivado da empresa.
 *
 * @return array{ok: true, filename: string}|array{ok: false, error: string}
 */
function ecoletaLogoProcess(string $tmpPath, string $destDir, string $companyName): array
{
    $info = @getimagesize($tmpPath);
    if ($info === false) {
        return ['ok' => false, 'error' => 'O arquivo enviado não é uma imagem válida.'];
    }

    $src = match ($info[2]) {
        IMAGETYPE_PNG => @imagecreatefrompng($tmpPath),
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpPath),
        IMAGETYPE_WEBP => @imagecreatefromwebp($tmpPath),
        default => false,
    };
    if ($src === false) {
        return ['ok' => false, 'error' => 'Não consegui decodificar a imagem enviada.'];
    }

    $width = imagesx($src);
    $height = imagesy($src);
    $scale = min(1.0, ECOLETA_LOGO_MAX_WIDTH / $width, ECOLETA_LOGO_MAX_HEIGHT / $height);
    $newWidth = max(1, (int) round($width * $scale));
    $newHeight = max(1, (int) round($height * $scale));

    $dst = imagecreatetruecolor($newWidth, $newHeight);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, (int) imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    imagedestroy($src);

    if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        imagedestroy($dst);

        return ['ok' => false, 'error' => 'Não consegui preparar o diretório de uploads no servidor.'];
    }

    $filename = ecoletaLogoSlug($companyName) . '-' . bin2hex(random_bytes(3)) . '.png';
    $saved = @imagepng($dst, $destDir . '/' . $filename, 9);
    imagedestroy($dst);

    if (!$saved) {
        return ['ok' => false, 'error' => 'Não consegui gravar a imagem no servidor.'];
    }

    return ['ok' => true, 'filename' => $filename];
}

/**
 * Apaga do disco a logo de uma empresa excluída — só quando ela veio pelo
 * upload. Caminhos de fora de uploads/logos/ (os PNGs versionados em /logos/,
 * URLs externas) ficam como estão.
 */
function ecoletaLogoDeleteByUrl(string $logoUrl): void
{
    if (!str_starts_with($logoUrl, ECOLETA_LOGO_PUBLIC_PREFIX)) {
        return;
    }

    $path = ecoletaLogoUploadsDir() . '/' . basename($logoUrl);
    if (is_file($path)) {
        @unlink($path);
    }
}
