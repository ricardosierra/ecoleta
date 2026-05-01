"use client";

import { useEffect, useRef, useState } from "react";
import { cn } from "@/lib/cn";

type Props = {
  /** Lista de palavras que vão se alternar com efeito typed. */
  words: string[];
  /** ms por caractere ao digitar. */
  typeSpeed?: number;
  /** ms por caractere ao apagar. */
  deleteSpeed?: number;
  /** Pausa quando a palavra está totalmente digitada. */
  pauseAfterType?: number;
  /** Pausa entre apagar e digitar a próxima. */
  pauseAfterDelete?: number;
  /** Loop infinito? Se false, para na última palavra. */
  loop?: boolean;
  /** Classe extra para o wrapper inline. */
  className?: string;
  /** Classe extra para o cursor. */
  cursorClassName?: string;
  /** Mostrar cursor piscando. */
  showCursor?: boolean;
};

/**
 * Efeito typewriter inspirado em typed.js — digita uma palavra letra por
 * letra, pausa, apaga, e cicla pela próxima.
 *
 * SSR-friendly: renderiza a primeira palavra completa no servidor para que
 * o conteúdo apareça imediatamente. Após hidratação, inicia o ciclo. Respeita
 * `prefers-reduced-motion` (mantém a primeira palavra estática).
 */
export default function Typewriter({
  words,
  typeSpeed = 75,
  deleteSpeed = 35,
  pauseAfterType = 1600,
  pauseAfterDelete = 250,
  loop = true,
  className,
  cursorClassName,
  showCursor = true,
}: Props) {
  const firstWord = words[0] ?? "";
  const [text, setText] = useState(firstWord);
  const [active, setActive] = useState(false);
  const wordIndexRef = useRef(0);
  const phaseRef = useRef<"typing" | "pausing" | "deleting">("pausing");
  const charIndexRef = useRef(firstWord.length);

  useEffect(() => {
    if (typeof window === "undefined") return;
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
    if (words.length <= 1) return;

    // eslint-disable-next-line react-hooks/set-state-in-effect
    setActive(true);

    let timer: ReturnType<typeof setTimeout> | null = null;

    const tick = () => {
      const word = words[wordIndexRef.current] ?? "";

      if (phaseRef.current === "typing") {
        if (charIndexRef.current < word.length) {
          charIndexRef.current += 1;
          setText(word.slice(0, charIndexRef.current));
          timer = setTimeout(tick, typeSpeed);
        } else {
          phaseRef.current = "pausing";
          timer = setTimeout(() => {
            phaseRef.current = "deleting";
            tick();
          }, pauseAfterType);
        }
        return;
      }

      if (phaseRef.current === "deleting") {
        if (charIndexRef.current > 0) {
          charIndexRef.current -= 1;
          setText(word.slice(0, charIndexRef.current));
          timer = setTimeout(tick, deleteSpeed);
        } else {
          // Próxima palavra
          const nextIndex = wordIndexRef.current + 1;
          if (!loop && nextIndex >= words.length) {
            // Para no fim
            return;
          }
          wordIndexRef.current = nextIndex % words.length;
          phaseRef.current = "typing";
          timer = setTimeout(tick, pauseAfterDelete);
        }
        return;
      }
    };

    // Começa com pausa (a primeira palavra já está renderizada cheia via SSR)
    phaseRef.current = "pausing";
    timer = setTimeout(() => {
      phaseRef.current = "deleting";
      tick();
    }, pauseAfterType);

    return () => {
      if (timer) clearTimeout(timer);
    };
  }, [words, typeSpeed, deleteSpeed, pauseAfterType, pauseAfterDelete, loop]);

  return (
    <span
      className={cn("inline-flex items-baseline", className)}
      aria-live="polite"
    >
      <span>{text}</span>
      {showCursor && active && (
        <span
          aria-hidden
          className={cn(
            "inline-block w-[3px] ml-1 bg-current self-stretch animate-[typewriter-blink_1s_steps(2)_infinite]",
            cursorClassName
          )}
          style={{ minHeight: "0.9em" }}
        />
      )}
      <style jsx>{`
        @keyframes typewriter-blink {
          to {
            opacity: 0;
          }
        }
      `}</style>
    </span>
  );
}
