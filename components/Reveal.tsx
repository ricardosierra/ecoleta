"use client";

import { useEffect, useRef, useState } from "react";
import { cn } from "@/lib/cn";

type Props = {
  children: React.ReactNode;
  className?: string;
  delay?: number;
  as?: "div" | "section" | "article" | "li" | "header";
};

/**
 * Progressive-enhancement reveal:
 *  - SSR/no-JS: renderiza visível (sem animação).
 *  - Com JS: hidratação seta o estado para "to-animate" e aplica a transição
 *    quando o elemento entra no viewport. Se o usuário prefere reduced-motion,
 *    pula a animação.
 */
export default function Reveal({
  children,
  className,
  delay = 0,
  as = "div",
}: Props) {
  const ref = useRef<HTMLElement | null>(null);
  // Inicia "visible=true" para renderizar conteúdo no SSR / antes da hidratação.
  // Após hidratar, decidimos se vamos animar ou não.
  const [hydrated, setHydrated] = useState(false);
  const [visible, setVisible] = useState(true);

  useEffect(() => {
    // Marca como hidratado apenas no client.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setHydrated(true);

    if (
      typeof window !== "undefined" &&
      window.matchMedia("(prefers-reduced-motion: reduce)").matches
    ) {
      return; // mantém visible=true, sem animação
    }

    if (typeof IntersectionObserver === "undefined") {
      return;
    }

    const node = ref.current;
    if (!node) return;

    const rect = node.getBoundingClientRect();
    const inViewportNow =
      rect.top < window.innerHeight && rect.bottom > 0;

    if (inViewportNow) {
      // Já está no viewport — mantém visível, sem animar.
      return;
    }

    // Está abaixo da dobra: esconde e anima quando entrar.
    setVisible(false);
    const obs = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            setVisible(true);
            obs.disconnect();
          }
        });
      },
      { threshold: 0.12, rootMargin: "0px 0px -40px 0px" }
    );
    obs.observe(node);
    return () => obs.disconnect();
  }, []);

  const Tag = as;
  const animating = hydrated; // só aplica transição depois de hidratado

  return (
    <Tag
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      ref={ref as any}
      style={{ transitionDelay: visible ? `${delay}ms` : "0ms" }}
      className={cn(
        animating && "transition-[opacity,transform] duration-700 ease-out will-change-transform",
        visible ? "opacity-100 translate-y-0" : "opacity-0 translate-y-4",
        className
      )}
    >
      {children}
    </Tag>
  );
}
