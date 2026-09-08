import { describe, expect, it } from "vitest";
import {
  LOGO_ASPECT,
  LOGO_MAX_ZOOM,
  LOGO_OUTPUT,
  clampOffset,
  clampZoom,
  containScale,
  exportPlan,
  frameFor,
  imageRect,
  rescaleOffset,
} from "@/lib/logo-crop";

const frame = frameFor(500); // 500×200

describe("logo-crop — moldura e escala", () => {
  it("a moldura tem a proporção do carrossel (5:2)", () => {
    expect(LOGO_ASPECT).toBe(2.5);
    expect(frame).toEqual({ width: 500, height: 200 });
    expect(LOGO_OUTPUT.width / LOGO_OUTPUT.height).toBe(LOGO_ASPECT);
  });

  it("contain: a imagem inteira cabe na moldura pelo lado que limita", () => {
    expect(containScale({ width: 1000, height: 200 }, frame)).toBe(0.5);
    expect(containScale({ width: 100, height: 100 }, frame)).toBe(2);
    expect(containScale({ width: 500, height: 200 }, frame)).toBe(1);
  });

  it("zoom fica entre 1× e o máximo, e valor inválido cai para 1×", () => {
    expect(clampZoom(0.2)).toBe(1);
    expect(clampZoom(99)).toBe(LOGO_MAX_ZOOM);
    expect(clampZoom(2.5)).toBe(2.5);
    expect(clampZoom(Number.NaN)).toBe(1);
  });
});

describe("logo-crop — deslocamento", () => {
  const image = { width: 1000, height: 200 };

  it("imagem menor que a moldura fica centrada no eixo em que sobra espaço", () => {
    // contain: 1000×200 → 500×100; sobra altura, nunca largura.
    expect(clampOffset(image, frame, 0.5, { x: 40, y: 30 })).toEqual({ x: 0, y: 0 });
  });

  it("imagem maior que a moldura pode andar até encostar a borda, sem deixar vão", () => {
    // zoom 2×: 1000×200 fica 1000×200 na moldura de 500×200 → sobra 250 para cada lado.
    expect(clampOffset(image, frame, 1, { x: 400, y: 10 })).toEqual({ x: 250, y: 0 });

    const esquerda = clampOffset(image, frame, 1, { x: -400, y: -10 });
    expect(esquerda.x).toBe(-250);
    expect(esquerda.y).toBeCloseTo(0);

    expect(clampOffset(image, frame, 1, { x: 120, y: 0 })).toEqual({ x: 120, y: 0 });
  });

  it("desenha a imagem centrada mais o deslocamento", () => {
    expect(imageRect(image, frame, 0.5, { x: 0, y: 0 })).toEqual({ x: 0, y: 50, width: 500, height: 100 });
    expect(imageRect(image, frame, 1, { x: 100, y: 0 })).toEqual({ x: -150, y: 0, width: 1000, height: 200 });
  });

  it("ao mudar o zoom o deslocamento acompanha, mantendo o mesmo ponto no centro", () => {
    expect(rescaleOffset({ x: 100, y: -20 }, 1, 2)).toEqual({ x: 200, y: -40 });
    expect(rescaleOffset({ x: 100, y: -20 }, 0, 2)).toEqual({ x: 100, y: -20 });
  });
});

describe("logo-crop — exportação", () => {
  it("imagem grande sai no canvas padrão, reduzida", () => {
    const plan = exportPlan({ width: 2000, height: 800 }, frame, 0.25, { x: 0, y: 0 });

    expect(plan.canvas).toEqual({ width: 600, height: 240 });
    expect(plan.draw).toEqual({ x: 0, y: 0, width: 600, height: 240 });
  });

  it("recorte com zoom desenha a imagem maior que o canvas, deslocada", () => {
    // 2000×800 a 0.5 = 1000×400 na moldura de 500×200, centro puxado 100px à esquerda.
    const plan = exportPlan({ width: 2000, height: 800 }, frame, 0.5, { x: -100, y: 0 });

    expect(plan.canvas).toEqual({ width: 600, height: 240 });
    expect(plan.draw.width).toBeCloseTo(1200);
    expect(plan.draw.height).toBeCloseTo(480);
    // (250 − 500 − 100) × 1.2
    expect(plan.draw.x).toBeCloseTo(-420);
    expect(plan.draw.y).toBeCloseTo(-120);
  });

  it("logo pequena nunca é ampliada: o canvas encolhe e ela sai em 1:1", () => {
    // 100×40 cabe em 500×200 com escala 5 — no canvas de 600 seria 6× o original.
    const plan = exportPlan({ width: 100, height: 40 }, frame, 5, { x: 0, y: 0 });

    expect(plan.canvas).toEqual({ width: 100, height: 40 });
    expect(plan.draw.x).toBeCloseTo(0);
    expect(plan.draw.y).toBeCloseTo(0);
    expect(plan.draw.width).toBeCloseTo(100);
    expect(plan.draw.height).toBeCloseTo(40);
  });
});
