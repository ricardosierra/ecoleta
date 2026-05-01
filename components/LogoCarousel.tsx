"use client";

import { cn } from "@/lib/cn";

type Item = {
  name: string;
};

type Props = {
  items: Item[];
  className?: string;
};

/**
 * Carrossel de logos com auto-scroll via CSS animation. Pausa no hover.
 *
 * Quando os logos reais chegarem, trocar o conteúdo de cada item por
 * <Image src=... alt=... /> mantendo a estrutura de duplicação.
 */
export default function LogoCarousel({ items, className }: Props) {
  if (items.length === 0) return null;
  const doubled = [...items, ...items];

  return (
    <div
      className={cn(
        "relative overflow-hidden",
        "[mask-image:linear-gradient(to_right,transparent,black_8%,black_92%,transparent)]",
        className
      )}
    >
      <ul
        className="flex gap-12 animate-[scroll_40s_linear_infinite] hover:[animation-play-state:paused]"
        aria-label="Clientes atendidos pela Ecoleta"
      >
        {doubled.map((item, i) => (
          <li
            key={`${item.name}-${i}`}
            className="shrink-0 flex items-center justify-center min-w-[180px] h-16 px-6 rounded-[10px] bg-white border border-(--color-border-light) text-(--color-text-muted) text-sm font-semibold uppercase tracking-wider grayscale hover:grayscale-0 transition-[filter,color] duration-300 hover:text-(--color-secondary)"
          >
            {item.name}
          </li>
        ))}
      </ul>

      <style jsx>{`
        @keyframes scroll {
          from {
            transform: translateX(0);
          }
          to {
            transform: translateX(-50%);
          }
        }
      `}</style>
    </div>
  );
}
