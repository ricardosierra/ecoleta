<?php
declare(strict_types=1);

/**
 * Upload e tratamento das logos de empresas parceiras.
 *
 * O arquivo enviado nunca é movido para o webroot como veio: a imagem é
 * decodificada pelo GD, tratada e re-encodada em um PNG novo. O tratamento:
 *
 *  1. tudo vira truecolor com canal alfa — inclusive PNG de paleta e PNG RGB
 *     com cor transparente (tRNS), que o GD reamostra como fundo preto sólido
 *     se ninguém converter antes;
 *  2. margens vazias são aparadas: transparentes, ou brancas quando os quatro
 *     cantos são brancos (fundo colorido de propósito fica como está);
 *  3. o resultado é reduzido (nunca ampliado) para caber em 600×360.
 *
 * Isso normaliza o formato, descarta metadados e elimina qualquer payload
 * embutido no arquivo original. Os PNGs finais moram em uploads/logos/ dentro
 * do webroot — fora de out/, que é o que o deploy FTP sobrescreve, então
 * sobrevivem a qualquer publicação.
 */

const ECOLETA_LOGO_MAX_BYTES = 4 * 1024 * 1024;
const ECOLETA_LOGO_MAX_WIDTH = 600;
const ECOLETA_LOGO_MAX_HEIGHT = 360;
const ECOLETA_LOGO_PUBLIC_PREFIX = '/uploads/logos/';

/** Lado máximo antes da varredura de margens — limita o custo pixel a pixel. */
const ECOLETA_LOGO_SCAN_MAX_SIDE = 1200;

/** Alfa do GD a partir do qual o pixel conta como vazio (0 opaco … 127 transparente). */
const ECOLETA_LOGO_ALPHA_EMPTY = 100;

/** Canal mínimo (0–255) para um canto contar como branco. */
const ECOLETA_LOGO_WHITE_MIN = 235;

/** Tolerância por canal ao comparar um pixel com o branco dos cantos. */
const ECOLETA_LOGO_WHITE_TOLERANCE = 18;

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

/** O servidor consegue tratar imagens? Sem GD o upload falha antes de gravar. */
function ecoletaLogoServerSupportsImages(): bool
{
    return function_exists('imagecreatetruecolor') && function_exists('imagepng');
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

/** Canvas truecolor com alfa, todo transparente. */
function ecoletaLogoCanvas(int $width, int $height): GdImage
{
    $img = imagecreatetruecolor($width, $height);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 0, 0, 0, 127));

    return $img;
}

/**
 * Garante truecolor com canal alfa de verdade.
 *
 * PNG de paleta e PNG RGB com tRNS chegam do GD com uma "cor transparente" em
 * vez de alfa por pixel; imagecopyresampled ignora essa marca em imagens
 * truecolor e o fundo sai preto sólido. Aqui cada pixel dessa cor vira alfa
 * 127 antes de qualquer reamostragem. Devolve a mesma imagem quando não há o
 * que converter.
 */
function ecoletaLogoToTruecolorAlpha(GdImage $img): GdImage
{
    $transparent = imagecolortransparent($img);

    if (imageistruecolor($img) && $transparent < 0) {
        imagealphablending($img, false);
        imagesavealpha($img, true);

        return $img;
    }

    $width = imagesx($img);
    $height = imagesy($img);
    $out = ecoletaLogoCanvas($width, $height);

    if ($transparent < 0) {
        imagecopy($out, $img, 0, 0, 0, 0, $width, $height);
        imagedestroy($img);

        return $out;
    }

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $index = imagecolorat($img, $x, $y);
            if ($index === $transparent) {
                continue;
            }
            $c = imagecolorsforindex($img, $index);
            imagesetpixel($out, $x, $y, (int) imagecolorallocatealpha($out, $c['red'], $c['green'], $c['blue'], $c['alpha']));
        }
    }
    imagedestroy($img);

    return $out;
}

/** Reamostra para $width×$height em um canvas novo com alfa; destrói a origem. */
function ecoletaLogoResample(GdImage $src, int $width, int $height): GdImage
{
    $dst = ecoletaLogoCanvas($width, $height);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $width, $height, imagesx($src), imagesy($src));
    imagedestroy($src);

    return $dst;
}

/** Reduz (nunca amplia) para caber em $maxWidth×$maxHeight. */
function ecoletaLogoFit(GdImage $src, int $maxWidth, int $maxHeight): GdImage
{
    $width = imagesx($src);
    $height = imagesy($src);
    $scale = min(1.0, $maxWidth / $width, $maxHeight / $height);
    if ($scale >= 1.0) {
        return $src;
    }

    return ecoletaLogoResample($src, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
}

/**
 * Cor de fundo aparável, lida dos quatro cantos: [r, g, b] quando todos são
 * brancos e concordam entre si; null quando o fundo é transparente, colorido
 * ou os cantos divergem — nesses casos só a transparência conta como vazio.
 *
 * @return array{0:int,1:int,2:int}|null
 */
function ecoletaLogoWhiteBackground(GdImage $img): ?array
{
    $width = imagesx($img);
    $height = imagesy($img);
    $corners = [[0, 0], [$width - 1, 0], [0, $height - 1], [$width - 1, $height - 1]];
    $sum = [0, 0, 0];

    foreach ($corners as [$x, $y]) {
        $p = imagecolorat($img, $x, $y);
        $alpha = ($p >> 24) & 0x7F;
        $rgb = [($p >> 16) & 0xFF, ($p >> 8) & 0xFF, $p & 0xFF];

        if ($alpha >= ECOLETA_LOGO_ALPHA_EMPTY || min($rgb) < ECOLETA_LOGO_WHITE_MIN) {
            return null;
        }

        $sum = [$sum[0] + $rgb[0], $sum[1] + $rgb[1], $sum[2] + $rgb[2]];
    }

    return [(int) round($sum[0] / 4), (int) round($sum[1] / 4), (int) round($sum[2] / 4)];
}

/**
 * Caixa que contém tudo o que não é fundo: {x, y, width, height}. Null quando
 * a imagem inteira é fundo (ou inteira conteúdo — não há o que aparar).
 *
 * Varre linhas de cima e de baixo até achar conteúdo, depois colunas só
 * dentro dessa faixa: numa logo típica o custo é uma fração da imagem.
 *
 * @return array{x:int,y:int,width:int,height:int}|null
 */
function ecoletaLogoContentBox(GdImage $img): ?array
{
    $width = imagesx($img);
    $height = imagesy($img);
    $white = ecoletaLogoWhiteBackground($img);

    $isEmpty = static function (int $x, int $y) use ($img, $white): bool {
        $p = imagecolorat($img, $x, $y);
        if ((($p >> 24) & 0x7F) >= ECOLETA_LOGO_ALPHA_EMPTY) {
            return true;
        }
        if ($white === null) {
            return false;
        }

        return abs((($p >> 16) & 0xFF) - $white[0]) <= ECOLETA_LOGO_WHITE_TOLERANCE
            && abs((($p >> 8) & 0xFF) - $white[1]) <= ECOLETA_LOGO_WHITE_TOLERANCE
            && abs(($p & 0xFF) - $white[2]) <= ECOLETA_LOGO_WHITE_TOLERANCE;
    };

    $rowEmpty = static function (int $y) use ($width, $isEmpty): bool {
        for ($x = 0; $x < $width; $x++) {
            if (!$isEmpty($x, $y)) {
                return false;
            }
        }

        return true;
    };

    $top = 0;
    while ($top < $height && $rowEmpty($top)) {
        $top++;
    }
    if ($top === $height) {
        return null;
    }

    $bottom = $height - 1;
    while ($bottom > $top && $rowEmpty($bottom)) {
        $bottom--;
    }

    $colEmpty = static function (int $x) use ($top, $bottom, $isEmpty): bool {
        for ($y = $top; $y <= $bottom; $y++) {
            if (!$isEmpty($x, $y)) {
                return false;
            }
        }

        return true;
    };

    $left = 0;
    while ($left < $width && $colEmpty($left)) {
        $left++;
    }
    $right = $width - 1;
    while ($right > $left && $colEmpty($right)) {
        $right--;
    }

    $box = ['x' => $left, 'y' => $top, 'width' => $right - $left + 1, 'height' => $bottom - $top + 1];

    return $box['width'] === $width && $box['height'] === $height ? null : $box;
}

/** Recorta a caixa em um canvas novo com alfa; destrói a origem. */
function ecoletaLogoCrop(GdImage $src, array $box): GdImage
{
    $dst = ecoletaLogoCanvas($box['width'], $box['height']);
    imagecopy($dst, $src, 0, 0, $box['x'], $box['y'], $box['width'], $box['height']);
    imagedestroy($src);

    return $dst;
}

/**
 * Decodifica, trata (alfa → aparar margens → caber em 600×360) e grava o PNG
 * em $destDir com nome derivado da empresa.
 *
 * @return array{ok: true, filename: string}|array{ok: false, error: string}
 */
function ecoletaLogoProcess(string $tmpPath, string $destDir, string $companyName): array
{
    if (!ecoletaLogoServerSupportsImages()) {
        error_log('logo_lib: extensão GD indisponível — upload de logo recusado.');

        return ['ok' => false, 'error' => 'O servidor está sem suporte a imagens (extensão GD). Informe um caminho em vez do arquivo.'];
    }

    $info = @getimagesize($tmpPath);
    if ($info === false) {
        return ['ok' => false, 'error' => 'O arquivo enviado não é uma imagem válida.'];
    }

    $src = match ($info[2]) {
        IMAGETYPE_PNG => @imagecreatefrompng($tmpPath),
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpPath),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false,
        default => false,
    };
    if ($src === false) {
        return ['ok' => false, 'error' => 'Não consegui decodificar a imagem enviada.'];
    }

    $src = ecoletaLogoToTruecolorAlpha($src);
    $src = ecoletaLogoFit($src, ECOLETA_LOGO_SCAN_MAX_SIDE, ECOLETA_LOGO_SCAN_MAX_SIDE);

    $box = ecoletaLogoContentBox($src);
    if ($box !== null) {
        $src = ecoletaLogoCrop($src, $box);
    }

    $dst = ecoletaLogoFit($src, ECOLETA_LOGO_MAX_WIDTH, ECOLETA_LOGO_MAX_HEIGHT);

    if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        imagedestroy($dst);
        error_log("logo_lib: não consegui criar {$destDir}.");

        return ['ok' => false, 'error' => 'Não consegui preparar o diretório de uploads no servidor.'];
    }

    if (!is_writable($destDir)) {
        imagedestroy($dst);
        error_log("logo_lib: sem permissão de escrita em {$destDir}.");

        return ['ok' => false, 'error' => 'O servidor não tem permissão de escrita em uploads/logos.'];
    }

    $filename = ecoletaLogoSlug($companyName) . '-' . bin2hex(random_bytes(3)) . '.png';
    $saved = @imagepng($dst, $destDir . '/' . $filename, 9);
    imagedestroy($dst);

    if (!$saved) {
        error_log("logo_lib: imagepng falhou em {$destDir}/{$filename}.");

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
