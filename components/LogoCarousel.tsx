"use client";

import { useEffect, useState } from "react";
import Image from "next/image";
import { cn } from "@/lib/cn";

type Item = {
  name: string;
  /** Caminho da logo em /public (ex: "/logos/heineken.png") ou URL remota. Se ausente, renderiza só o nome em texto. */
  src?: string;
};

type Props = { items: Item[]; className?: string };

export default function LogoCarousel({ items: initialItems, className }: Props) {
  const [items, setItems] = useState<Item[]>(initialItems);

  useEffect(() => {
    let isMounted = true;
    fetch("/api/partners/index.php?public=1")
      .then((res) => {
        if (!res.ok) throw new Error("Falha ao carregar parceiros");
        return res.json();
      })
      .then((data) => {
        if (isMounted && data.ok && Array.isArray(data.partners) && data.partners.length > 0) {
          setItems(
            data.partners.map((p: { name: string; src: string }) => ({
              name: p.name,
              src: p.src,
            }))
          );
        }
      })
      .catch(() => {
        // Mantém fallback inicial em caso de erro de rede ou ambiente estático
      });

    return () => {
      isMounted = false;
    };
  }, []);

  if (items.length === 0) return null;

  return (
    <ul
      className={cn(
        "grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6",
        className
      )}
      aria-label="Clientes atendidos pela Ecoleva"
    >
      {items.map((item) => (
        <li key={item.name} className={cardClass}>
          {renderItem(item)}
        </li>
      ))}
    </ul>
  );
}

const cardClass =
  "flex h-24 items-center justify-center rounded-[10px] border border-(--color-border-light) bg-(--color-bg-light) px-5 transition-[transform,box-shadow,border-color] duration-200 hover:-translate-y-1 hover:border-(--color-accent) hover:shadow-[var(--shadow-sm)]";

function renderItem(item: Item) {
  return item.src ? (
    <div className="relative h-14 w-full max-w-[140px] pointer-events-none">
      <Image
        src={item.src}
        alt={item.name}
        fill
        sizes="(max-width: 640px) 42vw, (max-width: 1024px) 22vw, 140px"
        className="object-contain"
        draggable={false}
        unoptimized
      />
    </div>
  ) : (
    <span className="text-(--color-text-muted) text-sm font-bold uppercase tracking-widest">
      {item.name}
    </span>
  );
}
