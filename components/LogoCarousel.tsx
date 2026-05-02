"use client";

import { cn } from "@/lib/cn";

type Item = { name: string };
type Props = { items: Item[]; className?: string };

// When real logo files arrive, replace each <li> body with:
//   <Image src={item.src} alt={item.name} width={160} height={48} className="object-contain" />
export default function LogoCarousel({ items, className }: Props) {
  if (items.length === 0) return null;

  const half = Math.ceil(items.length / 2);
  const row1 = [...items.slice(0, half), ...items.slice(0, half)];
  const row2 = [...items.slice(half), ...items.slice(half)];

  const card =
    "shrink-0 flex items-center justify-center min-w-[190px] h-[72px] px-8 rounded-[10px] bg-white border border-gray-100 shadow-[0_1px_4px_rgba(0,0,0,0.06)] text-(--color-text-muted) text-sm font-bold uppercase tracking-widest select-none cursor-default transition-[color,box-shadow] duration-300 hover:text-(--color-bg-dark) hover:shadow-[0_4px_14px_rgba(0,0,0,0.11)]";

  return (
    <div
      className={cn(
        "relative overflow-hidden space-y-3",
        "[mask-image:linear-gradient(to_right,transparent,black_10%,black_90%,transparent)]",
        className
      )}
    >
      <ul
        className="flex gap-3 animate-[marquee-left_42s_linear_infinite] hover:[animation-play-state:paused]"
        aria-label="Clientes atendidos pela Ecoleta"
      >
        {row1.map((item, i) => (
          <li key={`r1-${item.name}-${i}`} className={card}>
            {item.name}
          </li>
        ))}
      </ul>

      <ul
        className="flex gap-3 animate-[marquee-right_48s_linear_infinite] hover:[animation-play-state:paused]"
        aria-hidden="true"
      >
        {row2.map((item, i) => (
          <li key={`r2-${item.name}-${i}`} className={card}>
            {item.name}
          </li>
        ))}
      </ul>

      <style jsx>{`
        @keyframes marquee-left {
          from { transform: translateX(0); }
          to   { transform: translateX(-50%); }
        }
        @keyframes marquee-right {
          from { transform: translateX(-50%); }
          to   { transform: translateX(0); }
        }
      `}</style>
    </div>
  );
}
