"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { DashboardModal, ModalActions } from "@/components/DashboardModal";
import { CropIcon } from "@/components/icons";
import {
  LOGO_ASPECT,
  LOGO_MAX_ZOOM,
  LOGO_MIN_ZOOM,
  clampOffset,
  clampZoom,
  containScale,
  exportPlan,
  frameFor,
  imageRect,
  rescaleOffset,
  type Offset,
  type Size,
} from "@/lib/logo-crop";

type LogoCropperProps = {
  file: File;
  onCancel: () => void;
  /** PNG já recortado na proporção do carrossel. */
  onApply: (blob: Blob) => void;
};

/** Largura usada enquanto a moldura ainda não foi medida (e no jsdom). */
const FALLBACK_FRAME_WIDTH = 560;

const ZOOM_STEP = 0.25;

const NUDGE_PX = 6;

type Drag = { origin: Offset; startX: number; startY: number };

type Pinch = { startDistance: number; startZoom: number };

/**
 * Editor de recorte da logo: a imagem aparece dentro de uma moldura 5:2 com o
 * fundo do carrossel, o operador aproxima (slider, botões, pinça) e arrasta;
 * "Aplicar" exporta a moldura como PNG com transparência.
 *
 * Toda a geometria vem de lib/logo-crop.ts; aqui só há DOM e eventos.
 */
export function LogoCropper({ file, onCancel, onApply }: LogoCropperProps) {
  const [url] = useState(() => URL.createObjectURL(file));
  const [image, setImage] = useState<Size | null>(null);
  const [failed, setFailed] = useState(false);
  const [zoom, setZoom] = useState(LOGO_MIN_ZOOM);
  const [offset, setOffset] = useState<Offset>({ x: 0, y: 0 });
  const [frameWidth, setFrameWidth] = useState(FALLBACK_FRAME_WIDTH);
  const [exporting, setExporting] = useState(false);

  const imgRef = useRef<HTMLImageElement | null>(null);
  const frameRef = useRef<HTMLDivElement | null>(null);
  const pointers = useRef(new Map<number, { x: number; y: number }>());
  const drag = useRef<Drag | null>(null);
  const pinch = useRef<Pinch | null>(null);

  useEffect(() => () => URL.revokeObjectURL(url), [url]);

  // A imagem é carregada fora do DOM: é esse objeto que vai para o canvas.
  useEffect(() => {
    const img = new Image();
    img.onload = () => {
      const width = img.naturalWidth || img.width;
      const height = img.naturalHeight || img.height;
      if (width > 0 && height > 0) {
        imgRef.current = img;
        setImage({ width, height });
      } else {
        setFailed(true);
      }
    };
    img.onerror = () => setFailed(true);
    img.src = url;

    return () => {
      img.onload = null;
      img.onerror = null;
    };
  }, [url]);

  // Mede a moldura no commit (ref de callback) e acompanha redimensionamentos.
  const measureFrame = useCallback((node: HTMLDivElement | null) => {
    frameRef.current = node;
    if (node) {
      const width = node.getBoundingClientRect().width;
      if (width > 0) setFrameWidth(width);
    }
  }, []);

  useEffect(() => {
    const node = frameRef.current;
    if (!node || typeof ResizeObserver === "undefined") return;

    const observer = new ResizeObserver((entries) => {
      const width = entries[0]?.contentRect.width ?? 0;
      if (width > 0) setFrameWidth(width);
    });
    observer.observe(node);

    return () => observer.disconnect();
  }, []);

  const frame = frameFor(frameWidth);
  const base = image ? containScale(image, frame) : 1;
  const scale = base * zoom;
  const rect = image ? imageRect(image, frame, scale, clampOffset(image, frame, scale, offset)) : null;

  const applyZoom = (next: number) => {
    if (!image) return;
    const target = clampZoom(next);
    setOffset((current) => clampOffset(image, frame, base * target, rescaleOffset(current, base * zoom, base * target)));
    setZoom(target);
  };

  const move = (delta: Offset) => {
    if (!image) return;
    setOffset((current) => clampOffset(image, frame, scale, { x: current.x + delta.x, y: current.y + delta.y }));
  };

  const onPointerDown = (event: React.PointerEvent<HTMLDivElement>) => {
    if (!image) return;
    event.currentTarget.setPointerCapture?.(event.pointerId);
    pointers.current.set(event.pointerId, { x: event.clientX, y: event.clientY });

    if (pointers.current.size === 2) {
      const [a, b] = [...pointers.current.values()];
      pinch.current = { startDistance: Math.hypot(a.x - b.x, a.y - b.y) || 1, startZoom: zoom };
      drag.current = null;
    } else {
      drag.current = { origin: offset, startX: event.clientX, startY: event.clientY };
    }
  };

  const onPointerMove = (event: React.PointerEvent<HTMLDivElement>) => {
    if (!pointers.current.has(event.pointerId)) return;
    pointers.current.set(event.pointerId, { x: event.clientX, y: event.clientY });

    if (pinch.current && pointers.current.size >= 2) {
      const [a, b] = [...pointers.current.values()];
      const distance = Math.hypot(a.x - b.x, a.y - b.y);
      applyZoom(pinch.current.startZoom * (distance / pinch.current.startDistance));
      return;
    }

    if (drag.current && image) {
      const { origin, startX, startY } = drag.current;
      setOffset(
        clampOffset(image, frame, scale, {
          x: origin.x + (event.clientX - startX),
          y: origin.y + (event.clientY - startY),
        })
      );
    }
  };

  const onPointerUp = (event: React.PointerEvent<HTMLDivElement>) => {
    pointers.current.delete(event.pointerId);
    event.currentTarget.releasePointerCapture?.(event.pointerId);
    if (pointers.current.size < 2) pinch.current = null;
    if (pointers.current.size === 0) drag.current = null;
  };

  const onKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
    const deltas: Record<string, Offset> = {
      ArrowLeft: { x: -NUDGE_PX, y: 0 },
      ArrowRight: { x: NUDGE_PX, y: 0 },
      ArrowUp: { x: 0, y: -NUDGE_PX },
      ArrowDown: { x: 0, y: NUDGE_PX },
    };
    const delta = deltas[event.key];
    if (delta) {
      event.preventDefault();
      move(delta);
    } else if (event.key === "+" || event.key === "=") {
      event.preventDefault();
      applyZoom(zoom + ZOOM_STEP);
    } else if (event.key === "-") {
      event.preventDefault();
      applyZoom(zoom - ZOOM_STEP);
    }
  };

  const apply = () => {
    const img = imgRef.current;
    if (!img || !image || exporting) return;

    const plan = exportPlan(image, frame, scale, clampOffset(image, frame, scale, offset));
    const canvas = document.createElement("canvas");
    canvas.width = plan.canvas.width;
    canvas.height = plan.canvas.height;

    const ctx = canvas.getContext("2d");
    if (!ctx) {
      setFailed(true);
      return;
    }

    setExporting(true);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = "high";
    ctx.drawImage(img, plan.draw.x, plan.draw.y, plan.draw.width, plan.draw.height);
    canvas.toBlob((blob) => {
      setExporting(false);
      if (blob) {
        onApply(blob);
      } else {
        setFailed(true);
      }
    }, "image/png");
  };

  return (
    <DashboardModal
      title="Ajustar logo"
      icon={<CropIcon width={18} height={18} />}
      tone="success"
      size="lg"
      onClose={onCancel}
    >
      <div
        ref={measureFrame}
        role="img"
        aria-label="Área de recorte da logo"
        tabIndex={0}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={onPointerUp}
        onPointerCancel={onPointerUp}
        onKeyDown={onKeyDown}
        style={{ aspectRatio: `${LOGO_ASPECT}` }}
        className={`relative w-full select-none overflow-hidden rounded-[10px] border border-[var(--color-border-light)] bg-[var(--color-bg-light)] outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-accent)] ${
          image ? "cursor-grab active:cursor-grabbing" : ""
        } touch-none`}
      >
        {image && rect ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={url}
            alt=""
            draggable={false}
            className="pointer-events-none absolute max-w-none"
            style={{ left: rect.x, top: rect.y, width: rect.width, height: rect.height }}
          />
        ) : (
          <div className="absolute inset-0 flex items-center justify-center text-sm text-[var(--color-text-muted)]">
            {failed ? "Não consegui abrir essa imagem." : "Carregando imagem…"}
          </div>
        )}
      </div>

      <div className="mt-4 flex items-center gap-3">
        <button
          type="button"
          onClick={() => applyZoom(zoom - ZOOM_STEP)}
          disabled={!image || zoom <= LOGO_MIN_ZOOM}
          aria-label="Afastar"
          className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-white/15 text-lg leading-none text-white transition-colors hover:bg-white/10 disabled:opacity-40"
        >
          −
        </button>
        <input
          type="range"
          aria-label="Zoom"
          min={LOGO_MIN_ZOOM}
          max={LOGO_MAX_ZOOM}
          step={0.01}
          value={zoom}
          disabled={!image}
          onChange={(event) => applyZoom(Number(event.target.value))}
          className="w-full accent-[var(--color-accent)]"
        />
        <button
          type="button"
          onClick={() => applyZoom(zoom + ZOOM_STEP)}
          disabled={!image || zoom >= LOGO_MAX_ZOOM}
          aria-label="Aproximar"
          className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-white/15 text-lg leading-none text-white transition-colors hover:bg-white/10 disabled:opacity-40"
        >
          +
        </button>
      </div>

      <ModalActions>
        <button
          type="button"
          onClick={onCancel}
          className="inline-flex items-center justify-center rounded-full px-5 py-2.5 text-sm font-medium text-white/70 transition-colors hover:bg-white/5 hover:text-white"
        >
          Cancelar
        </button>
        <button
          type="button"
          onClick={apply}
          disabled={!image || failed || exporting}
          className="inline-flex items-center justify-center gap-2 rounded-full bg-[var(--color-accent)] px-6 py-2.5 text-sm font-semibold text-[var(--color-bg-dark)] transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
        >
          <CropIcon width={16} height={16} />
          {exporting ? "Aplicando…" : "Aplicar"}
        </button>
      </ModalActions>
    </DashboardModal>
  );
}
