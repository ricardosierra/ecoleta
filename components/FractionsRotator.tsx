"use client";

import { useEffect, useRef, useState } from "react";
import type { ReactNode } from "react";
import { cn } from "@/lib/cn";
import { usePrefersReducedMotion } from "@/lib/usePrefersReducedMotion";

export type Fraction = {
  name: string;
  tag: string;
  desc: string;
  /** Ícone já renderizado pelo caller (RSC) — em tamanho 32×32 idealmente. */
  icon: ReactNode;
  /** Tailwind class para o bullet colorido (ex: "bg-(--color-accent)") */
  bulletClass: string;
  /** Cor do ícone/destaques no card ativo (ex: "text-(--color-accent)") */
  accentClass: string;
  /** Tag de classe (ex: "CLASSE I · PERIGOSO") opcional acima do ícone */
  badge?: string;
};

type Props = {
  fractions: Fraction[];
  /** Intervalo de rotação em ms */
  interval?: number;
};

export default function FractionsRotator({
  fractions,
  interval = 2800,
}: Props) {
  const reduceMotion = usePrefersReducedMotion();
  const [active, setActive] = useState(0);
  const [paused, setPaused] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  const inViewRef = useRef(false);

  useEffect(() => {
    if (reduceMotion) return;
    const node = containerRef.current;
    if (!node) return;
    const io = new IntersectionObserver(
      (entries) => {
        entries.forEach((e) => {
          inViewRef.current = e.isIntersecting;
        });
      },
      { threshold: 0.25 }
    );
    io.observe(node);
    return () => io.disconnect();
  }, [reduceMotion]);

  useEffect(() => {
    if (reduceMotion || paused) return;
    const id = window.setInterval(() => {
      if (!inViewRef.current) return;
      setActive((i) => (i + 1) % fractions.length);
    }, interval);
    return () => window.clearInterval(id);
  }, [reduceMotion, paused, fractions.length, interval]);

  // Reduced-motion: grid estático
  if (reduceMotion) {
    return (
      <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {fractions.map((f) => (
          <li
            key={f.name}
            className="flex items-start gap-3 p-4 rounded-[10px] bg-[#0a1810] border border-(--color-border-dark)"
          >
            <span
              className={cn(
                "size-14 rounded-full flex items-center justify-center shrink-0 bg-white/5",
                f.accentClass
              )}
            >
              {f.icon}
            </span>
            <div>
              <p className="font-semibold">{f.name}</p>
              <p className="text-xs text-white/60">{f.tag}</p>
            </div>
          </li>
        ))}
      </ul>
    );
  }

  const current = fractions[active];

  return (
    <div
      ref={containerRef}
      className="grid gap-6 lg:grid-cols-[minmax(220px,1fr)_1.5fr]"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
    >
      {/* Lista vertical */}
      <ul className="flex flex-col gap-1" role="tablist" aria-label="Frações">
        {fractions.map((f, i) => {
          const isActive = i === active;
          return (
            <li key={f.name}>
              <button
                type="button"
                role="tab"
                aria-selected={isActive}
                onClick={() => setActive(i)}
                className={cn(
                  "w-full flex items-start gap-3 px-4 py-3 rounded-[10px] text-left transition-colors duration-200",
                  isActive
                    ? "bg-[#11231a] border border-(--color-accent)/30"
                    : "border border-transparent hover:bg-white/[0.03]"
                )}
              >
                <span
                  className={cn(
                    "mt-1.5 size-2.5 rounded-full shrink-0",
                    f.bulletClass
                  )}
                />
                <span className="flex flex-col">
                  <span
                    className={cn(
                      "font-bold text-base leading-tight transition-colors",
                      isActive ? "text-white" : "text-white/75"
                    )}
                  >
                    {f.name}
                  </span>
                  <span className="text-xs text-white/55 mt-0.5">{f.tag}</span>
                </span>
              </button>
            </li>
          );
        })}
      </ul>

      {/* Card ativo */}
      <div
        key={current.name}
        className="relative rounded-[14px] bg-[#0a1810] border border-(--color-border-dark) p-8 md:p-10 flex flex-col items-center justify-center text-center min-h-[240px] animate-[pop-in_400ms_ease-out]"
        aria-live="polite"
      >
        {current.badge && (
          <span
            className={cn(
              "absolute top-4 left-1/2 -translate-x-1/2 px-3 py-1 rounded-full border text-[10px] uppercase tracking-widest font-semibold",
              current.accentClass,
              "border-current"
            )}
          >
            {current.badge}
          </span>
        )}
        <span
          className={cn(
            "size-24 rounded-full flex items-center justify-center bg-white/5 border border-white/10 shadow-[0_0_32px_var(--color-accent-soft)] mb-5",
            current.accentClass
          )}
        >
          {current.icon}
        </span>
        <p className="text-xl font-bold text-white">{current.name}</p>
        <p className="mt-2 text-sm text-white/65 leading-relaxed max-w-md">
          {current.desc}
        </p>
        <span
          aria-hidden
          className="mt-6 inline-flex items-center justify-center size-8 rounded-full bg-white/5 border border-white/10 text-white/50"
        >
          <svg viewBox="0 0 24 24" width={14} height={14} fill="none" stroke="currentColor" strokeWidth={1.6} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
            <path d="M12 5v14" />
            <path d="m6 13 6 6 6-6" />
          </svg>
        </span>
      </div>
    </div>
  );
}
