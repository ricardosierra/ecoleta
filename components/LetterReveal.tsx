"use client";

import { useEffect, useRef, useState } from "react";
import { cn } from "@/lib/cn";

type Props = {
  text: string;
  className?: string;
  /** Delay before the text appears (ms). */
  delay?: number;
  /** Kept for call-site compatibility; static text reveals as one unit. */
  charDelay?: number;
};

/**
 * Reveals a complete text string when the element enters the viewport.
 * Static copy should never show partial words; the typed effect is reserved for
 * Typewriter.
 *
 * SSR: renders opacity-0 text (preserves layout, text still in DOM for SEO).
 * After hydration: fires immediately if already in viewport, otherwise waits.
 */
export default function LetterReveal({
  text,
  className,
  delay = 0,
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

  return (
    <span
      ref={ref}
      className={className}
      style={{
        opacity: 0,
        animationName: "letter-reveal",
        animationDuration: "220ms",
        animationTimingFunction: "ease-out",
        animationDelay: `${delay}ms`,
        animationFillMode: "forwards",
      }}
    >
      {text}
    </span>
  );
}
