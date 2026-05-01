import { cn } from "@/lib/cn";

type Position =
  | "top-left"
  | "top-right"
  | "bottom-left"
  | "bottom-right";

const positionClasses: Record<Position, string> = {
  "top-left": "top-0 left-0 -translate-x-1/3 -translate-y-1/3",
  "top-right": "top-0 right-0 translate-x-1/3 -translate-y-1/3",
  "bottom-left": "bottom-0 left-0 -translate-x-1/3 translate-y-1/3",
  "bottom-right": "bottom-0 right-0 translate-x-1/3 translate-y-1/3",
};

type Props = {
  position?: Position;
  /** Tamanho do svg em px. */
  size?: number;
  /** Rotação adicional em graus. */
  rotate?: number;
  /** Cor da folha (default: token --color-accent). */
  color?: string;
  /** Opacidade do svg. */
  opacity?: number;
  className?: string;
  /** Renderizar com animação flutuante leve. */
  floating?: boolean;
};

/**
 * Decoração de folha estilizada (estilo impactacomvoce.com.br).
 * Posicionada absolutamente em relação ao container pai (`relative`).
 */
export default function LeafDecor({
  position = "top-right",
  size = 220,
  rotate = 0,
  color = "var(--color-accent)",
  opacity = 0.18,
  className,
  floating = true,
}: Props) {
  return (
    <span
      aria-hidden
      className={cn(
        "absolute pointer-events-none select-none",
        positionClasses[position],
        floating && "motion-safe:animate-[leaf-float_8s_ease-in-out_infinite]",
        className
      )}
      style={{
        width: size,
        height: size,
        transform: `${positionClasses[position].includes("translate") ? "" : ""} rotate(${rotate}deg)`,
        opacity,
      }}
    >
      <svg
        viewBox="0 0 200 200"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        className="size-full"
      >
        {/* Folha grande, estilo orgânico */}
        <path
          d="M30 170 C 30 80, 80 30, 170 30 C 170 30, 170 110, 110 150 C 80 170, 50 175, 30 170 Z"
          fill={color}
        />
        {/* Nervura central */}
        <path
          d="M30 170 C 70 130, 110 90, 170 30"
          stroke="var(--color-bg-dark)"
          strokeOpacity="0.25"
          strokeWidth="2"
          fill="none"
        />
        {/* Nervuras laterais */}
        <path
          d="M55 150 L 95 130"
          stroke="var(--color-bg-dark)"
          strokeOpacity="0.2"
          strokeWidth="1.5"
        />
        <path
          d="M75 130 L 115 105"
          stroke="var(--color-bg-dark)"
          strokeOpacity="0.2"
          strokeWidth="1.5"
        />
        <path
          d="M100 100 L 140 70"
          stroke="var(--color-bg-dark)"
          strokeOpacity="0.2"
          strokeWidth="1.5"
        />
      </svg>
    </span>
  );
}
