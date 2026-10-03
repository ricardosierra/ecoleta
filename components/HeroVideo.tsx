"use client";

import { useEffect, useRef, useSyncExternalStore } from "react";

type Props = {
  className?: string;
  playbackRate?: number;
};

const REDUCED_MOTION = "(prefers-reduced-motion: reduce)";

function subscribe(onChange: () => void) {
  const query = window.matchMedia(REDUCED_MOTION);
  query.addEventListener("change", onChange);
  return () => query.removeEventListener("change", onChange);
}

const prefersReducedMotion = () => window.matchMedia(REDUCED_MOTION).matches;

// No HTML estático ainda não se sabe a preferência de ninguém. Assumir "reduzido"
// ali mantém o <video autoplay> fora do HTML: do contrário o navegador começaria
// a baixar cerca de 14 MB antes de o JavaScript poder olhar a preferência.
const serverSnapshot = () => true;

/**
 * Vídeo de fundo do hero.
 *
 * Quem pediu movimento reduzido ao sistema não recebe o vídeo: nem toca, nem
 * baixa. O que aparece é o fundo escuro da própria seção (token bg-dark) com o
 * overlay por cima, que já é a base de leitura do texto.
 *
 * O mp4 vem antes do webm: os dois cobrem os navegadores atuais, o navegador
 * usa o primeiro que sabe tocar, e o mp4 é o menor (13,5 MB contra 14,8 MB).
 * `preload="metadata"` é só a dica de não buscar mais do que o necessário
 * enquanto o vídeo não toca. O autoplay continua puxando o arquivo de qualquer
 * jeito, por isso a decisão que economiza banda é a de não renderizar.
 */
export default function HeroVideo({
  className,
  playbackRate = 0.65,
}: Props) {
  const ref = useRef<HTMLVideoElement>(null);
  const reduced = useSyncExternalStore(
    subscribe,
    prefersReducedMotion,
    serverSnapshot
  );

  useEffect(() => {
    const video = ref.current;
    if (!video) return;
    video.playbackRate = playbackRate;
  }, [playbackRate, reduced]);

  if (reduced) return null;

  return (
    <video
      ref={ref}
      aria-hidden
      autoPlay
      loop
      muted
      playsInline
      preload="metadata"
      className={className}
    >
      <source src="/hero-video.mp4" type="video/mp4" />
      <source src="/hero-video.webm" type="video/webm" />
    </video>
  );
}
