"use client";

import { useEffect, useRef, useState } from "react";
import { usePrefersReducedMotion } from "@/lib/usePrefersReducedMotion";

type Props = {
  value: number; // 0-100
  label: string;
  size?: number;
  /** Duração da animação de contagem em ms */
  duration?: number;
};

export default function DonutChart({
  value,
  label,
  size = 220,
  duration = 1500,
}: Props) {
  const stroke = 18;
  const radius = (size - stroke) / 2;
  const circumference = 2 * Math.PI * radius;

  const reduceMotion = usePrefersReducedMotion();
  const [animated, setAnimated] = useState(0);
  const containerRef = useRef<HTMLDivElement>(null);
  const startedRef = useRef(false);

  // Quando reduceMotion está ativo, o número final é mostrado direto.
  const displayValue = reduceMotion ? value : animated;

  useEffect(() => {
    if (reduceMotion) return;

    const node = containerRef.current;
    if (!node) return;

    const run = () => {
      if (startedRef.current) return;
      startedRef.current = true;
      const start = performance.now();
      let raf = 0;
      const tick = (now: number) => {
        const p = Math.min((now - start) / duration, 1);
        const eased = 1 - Math.pow(1 - p, 3);
        setAnimated(Math.round(eased * value));
        if (p < 1) raf = requestAnimationFrame(tick);
      };
      raf = requestAnimationFrame(tick);
      return () => cancelAnimationFrame(raf);
    };

    const io = new IntersectionObserver(
      (entries) => {
        if (entries.some((e) => e.isIntersecting)) {
          run();
          io.disconnect();
        }
      },
      { threshold: 0.3 }
    );
    io.observe(node);
    return () => io.disconnect();
  }, [value, duration, reduceMotion]);

  const offset = circumference - (displayValue / 100) * circumference;

  return (
    <div
      ref={containerRef}
      className="relative"
      style={{ width: size, height: size }}
    >
      <svg
        width={size}
        height={size}
        viewBox={`0 0 ${size} ${size}`}
        className="-rotate-90"
        aria-hidden
      >
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke="rgba(255,255,255,0.08)"
          strokeWidth={stroke}
        />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke="var(--color-accent)"
          strokeWidth={stroke}
          strokeDasharray={circumference}
          strokeDashoffset={offset}
          strokeLinecap="round"
        />
      </svg>
      <div
        className="absolute inset-0 flex flex-col items-center justify-center text-center"
        aria-label={`${value}% ${label}`}
      >
        <span className="text-5xl md:text-6xl font-bold text-(--color-accent) leading-none">
          {displayValue}%
        </span>
        <span className="mt-2 text-xs uppercase tracking-widest text-white/60">
          {label}
        </span>
      </div>
    </div>
  );
}
