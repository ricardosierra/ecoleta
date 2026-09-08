/**
 * Geometria do recorte de logo — módulo puro, sem DOM.
 *
 * A moldura tem a proporção da caixa do carrossel da home (140×56, ou 5:2).
 * A imagem começa inteira dentro dela ("contain") e o operador só aproxima e
 * arrasta; o que fica dentro da moldura vira o PNG enviado ao servidor.
 */

export type Size = { width: number; height: number };

export type Offset = { x: number; y: number };

export type Rect = { x: number; y: number; width: number; height: number };

/** Tamanho do PNG exportado. Mesma proporção da moldura. */
export const LOGO_OUTPUT: Size = { width: 600, height: 240 };

export const LOGO_ASPECT = LOGO_OUTPUT.width / LOGO_OUTPUT.height;

export const LOGO_MIN_ZOOM = 1;

export const LOGO_MAX_ZOOM = 4;

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value));
}

/** Moldura com a proporção da logo a partir da largura disponível. */
export function frameFor(width: number): Size {
  return { width, height: width / LOGO_ASPECT };
}

/** Escala (px da imagem → px da moldura) em que a imagem inteira cabe na moldura. */
export function containScale(image: Size, frame: Size): number {
  return Math.min(frame.width / image.width, frame.height / image.height);
}

export function clampZoom(zoom: number): number {
  return clamp(Number.isFinite(zoom) ? zoom : LOGO_MIN_ZOOM, LOGO_MIN_ZOOM, LOGO_MAX_ZOOM);
}

/**
 * Limita o deslocamento do centro da imagem em relação ao centro da moldura.
 * No eixo em que a imagem é menor que a moldura ela fica centrada; no eixo em
 * que é maior, nunca aparece vão entre a borda da imagem e a da moldura.
 */
export function clampOffset(image: Size, frame: Size, scale: number, offset: Offset): Offset {
  const maxX = Math.max(0, (image.width * scale - frame.width) / 2);
  const maxY = Math.max(0, (image.height * scale - frame.height) / 2);

  return { x: clamp(offset.x, -maxX, maxX), y: clamp(offset.y, -maxY, maxY) };
}

/** Retângulo, em px da moldura, onde a imagem é desenhada. */
export function imageRect(image: Size, frame: Size, scale: number, offset: Offset): Rect {
  const width = image.width * scale;
  const height = image.height * scale;

  return {
    x: frame.width / 2 + offset.x - width / 2,
    y: frame.height / 2 + offset.y - height / 2,
    width,
    height,
  };
}

/**
 * Deslocamento após mudar o zoom, mantendo o mesmo ponto da imagem no centro
 * da moldura (o deslocamento cresce na mesma proporção que a escala).
 */
export function rescaleOffset(offset: Offset, fromScale: number, toScale: number): Offset {
  if (fromScale <= 0) return offset;
  const ratio = toScale / fromScale;

  return { x: offset.x * ratio, y: offset.y * ratio };
}

export type ExportPlan = {
  /** Tamanho do canvas de saída. */
  canvas: Size;
  /** Onde desenhar a imagem inteira dentro desse canvas. */
  draw: Rect;
};

/**
 * Como exportar o recorte: canvas de saída e retângulo de desenho.
 *
 * O canvas padrão é 600×240, mas uma logo pequena nunca é ampliada além do
 * tamanho original — nesse caso o canvas encolhe na mesma proporção, e a
 * imagem sai em 1:1. O carrossel exibe em 140×56 de qualquer jeito.
 */
export function exportPlan(
  image: Size,
  frame: Size,
  scale: number,
  offset: Offset,
  output: Size = LOGO_OUTPUT
): ExportPlan {
  const frameToOutput = output.width / frame.width;
  const imageToOutput = scale * frameToOutput;
  const shrink = imageToOutput > 1 ? 1 / imageToOutput : 1;

  const rect = imageRect(image, frame, scale, offset);
  const factor = frameToOutput * shrink;

  return {
    canvas: {
      width: Math.max(1, Math.round(output.width * shrink)),
      height: Math.max(1, Math.round(output.height * shrink)),
    },
    draw: {
      x: rect.x * factor,
      y: rect.y * factor,
      width: rect.width * factor,
      height: rect.height * factor,
    },
  };
}
