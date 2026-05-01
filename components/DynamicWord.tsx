"use client";

import { useEffect, useState } from "react";
import { cn } from "@/lib/cn";

type Props = {
  words: string[];
  intervalMs?: number;
  className?: string;
};

export default function DynamicWord({
  words,
  intervalMs = 2200,
  className,
}: Props) {
  const [index, setIndex] = useState(0);
  const [show, setShow] = useState(true);

  useEffect(() => {
    if (typeof window === "undefined") return;
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

    const fadeOut = setTimeout(() => setShow(false), intervalMs - 350);
    const next = setTimeout(() => {
      setIndex((i) => (i + 1) % words.length);
      setShow(true);
    }, intervalMs);

    return () => {
      clearTimeout(fadeOut);
      clearTimeout(next);
    };
  }, [index, intervalMs, words.length]);

  return (
    <span
      className={cn(
        "inline-block text-(--color-accent) transition-[opacity,transform] duration-300 ease-out",
        show ? "opacity-100 translate-y-0" : "opacity-0 -translate-y-1.5",
        className
      )}
      aria-live="polite"
    >
      {words[index]}
    </span>
  );
}
