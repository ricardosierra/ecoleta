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
    let frame = 0;
    const activate = () => {
      frame = window.requestAnimationFrame(() => setActive(true));
    };

    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
      frame = window.requestAnimationFrame(() => setReduced(true));
      return () => window.cancelAnimationFrame(frame);
    }

    const el = ref.current;
    if (!el) return;

    const rect = el.getBoundingClientRect();
    if (rect.top < window.innerHeight && rect.bottom > 0) {
      activate();
      return () => window.cancelAnimationFrame(frame);
    }

    const obs = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          activate();
          obs.disconnect();
        }
      },
      { threshold: 0.1, rootMargin: "0px 0px -40px 0px" }
    );
    obs.observe(el);
    return () => {
      window.cancelAnimationFrame(frame);
      obs.disconnect();
    };
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

  // Split into word tokens so each word is wrapped in whitespace-nowrap,
  // preventing mid-word line breaks caused by per-letter inline-block spans.
  const tokens = text.split(/( )/);
  let charIndex = 0;

  return (
    <span ref={ref} className={className}>
      <span aria-hidden>
        {tokens.map((token, ti) => {
          if (token === " ") {
            const idx = charIndex++;
            return (
              <span
                key={`sp-${ti}`}
                style={{
                  opacity: 0,
                  animationName: "letter-reveal",
                  animationDuration: "1ms",
                  animationTimingFunction: "step-end",
                  animationDelay: `${delay + idx * charDelay}ms`,
                  animationFillMode: "forwards",
                }}
              >
                {" "}
              </span>
            );
          }
          const wordChars = token.split("").map((char, ci) => {
            const idx = charIndex++;
            return (
              <span
                key={`c-${ci}`}
                style={{
                  opacity: 0,
                  display: "inline",
                  animationName: "letter-reveal",
                  animationDuration: "1ms",
                  animationTimingFunction: "step-end",
                  animationDelay: `${delay + idx * charDelay}ms`,
                  animationFillMode: "forwards",
                }}
              >
                {char}
              </span>
            );
          });
          return (
            <span key={`w-${ti}`} className="inline-block whitespace-nowrap">
              {wordChars}
            </span>
          );
        })}
      </span>
      <span className="sr-only">{text}</span>
    </span>
  );
}
