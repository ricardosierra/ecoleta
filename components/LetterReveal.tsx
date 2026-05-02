"use client";

import { useEffect, useRef, useState } from "react";
import { cn } from "@/lib/cn";

type Props = {
  text: string;
  className?: string;
  /** Delay before the first character appears (ms). */
  delay?: number;
  /** Gap between each character appearing (ms). */
  charDelay?: number;
};

/**
 * Reveals a text string character-by-character (typewriter style) when the
 * element enters the viewport. Respects prefers-reduced-motion.
 *
 * SSR: renders opacity-0 text (preserves layout, text still in DOM for SEO).
 * After hydration: fires immediately if already in viewport, otherwise waits.
 */
export default function LetterReveal({
  text,
  className,
  delay = 0,
  charDelay = 28,
}: Props) {
  const ref = useRef<HTMLSpanElement>(null);
  const [active, setActive] = useState(false);
  const [reduced, setReduced] = useState(false);

  useEffect(() => {
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
      setReduced(true);
      return;
    }

    const el = ref.current;
    if (!el) return;

    const rect = el.getBoundingClientRect();
    if (rect.top < window.innerHeight && rect.bottom > 0) {
      setActive(true);
      return;
    }

    const obs = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          setActive(true);
          obs.disconnect();
        }
      },
      { threshold: 0.1, rootMargin: "0px 0px -40px 0px" }
    );
    obs.observe(el);
    return () => obs.disconnect();
  }, []);

  if (reduced) {
    return (
      <span ref={ref} className={className}>
        {text}
      </span>
    );
  }

  if (!active) {
    return (
      <span ref={ref} className={cn("opacity-0", className)}>
        {text}
      </span>
    );
  }

  return (
    <span ref={ref} className={className}>
      <span aria-hidden>
        {text.split("").map((char, i) => (
          <span
            key={i}
            className="inline-block"
            style={{
              opacity: 0,
              animationName: "letter-reveal",
              animationDuration: "1ms",
              animationTimingFunction: "step-end",
              animationDelay: `${delay + i * charDelay}ms`,
              animationFillMode: "forwards",
            }}
          >
            {char === " " ? " " : char}
          </span>
        ))}
      </span>
      <span className="sr-only">{text}</span>
    </span>
  );
}
