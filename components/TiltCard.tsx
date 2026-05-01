"use client";

import { useRef, useState } from "react";
import { cn } from "@/lib/cn";
import type { HTMLAttributes, ReactNode } from "react";

type Props = HTMLAttributes<HTMLDivElement> & {
  /** Intensidade do tilt em graus. */
  intensity?: number;
  /** Glare/highlight no hover? */
  glare?: boolean;
  children: ReactNode;
};

/**
 * Tilt 3D leve no hover — espelha o efeito do universal-tilt.js usado no
 * site de referência (impactacomvoce.com.br), sem dependência externa.
 *
 * Mouse-only: em touch o componente fica estático para não interferir.
 * Respeita prefers-reduced-motion.
 */
export default function TiltCard({
  intensity = 6,
  glare = true,
  children,
  className,
  style,
  ...rest
}: Props) {
  const ref = useRef<HTMLDivElement | null>(null);
  const [transform, setTransform] = useState<string>("");
  const [glarePos, setGlarePos] = useState({ x: 50, y: 50 });

  const handleMove = (e: React.PointerEvent<HTMLDivElement>) => {
    if (e.pointerType !== "mouse") return;
    if (
      typeof window !== "undefined" &&
      window.matchMedia("(prefers-reduced-motion: reduce)").matches
    )
      return;
    const node = ref.current;
    if (!node) return;
    const rect = node.getBoundingClientRect();
    const x = (e.clientX - rect.left) / rect.width;
    const y = (e.clientY - rect.top) / rect.height;
    const rx = (0.5 - y) * intensity;
    const ry = (x - 0.5) * intensity;
    setTransform(
      `perspective(900px) rotateX(${rx.toFixed(2)}deg) rotateY(${ry.toFixed(2)}deg) translateZ(0)`
    );
    setGlarePos({ x: x * 100, y: y * 100 });
  };

  const handleLeave = () => {
    setTransform("");
  };

  return (
    <div
      {...rest}
      ref={ref}
      onPointerMove={handleMove}
      onPointerLeave={handleLeave}
      style={{
        transform,
        transition: "transform 0.25s ease-out",
        transformStyle: "preserve-3d",
        ...style,
      }}
      className={cn("relative will-change-transform", className)}
    >
      {children}
      {glare && transform && (
        <span
          aria-hidden
          className="absolute inset-0 rounded-[inherit] pointer-events-none transition-opacity duration-200"
          style={{
            background: `radial-gradient(circle at ${glarePos.x}% ${glarePos.y}%, rgba(255,255,255,0.18) 0%, transparent 45%)`,
            mixBlendMode: "soft-light",
          }}
        />
      )}
    </div>
  );
}
