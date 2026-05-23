"use client";

import Image from "next/image";
import { useEffect, useRef, useState } from "react";
import { cn } from "@/lib/cn";

type Item = {
  name: string;
  /** Caminho da logo em /public (ex: "/logos/heineken.png"). Se ausente, renderiza só o nome em texto. */
  src?: string;
};

type Props = { items: Item[]; className?: string };

export default function LogoCarousel({ items, className }: Props) {
  if (items.length === 0) return null;

  const half = Math.ceil(items.length / 2);
  const row1 = items.slice(0, half);
  const row2 = items.slice(half);

  return (
    <div
      className={cn(
        "relative overflow-hidden space-y-3",
        "[mask-image:linear-gradient(to_right,transparent,black_10%,black_90%,transparent)]",
        className
      )}
    >
      <LogoRow items={row1} direction="left" ariaLabel="Clientes atendidos pela Ecoleva" />
      <LogoRow items={row2} direction="right" ariaHidden />
    </div>
  );
}

function LogoRow({
  items,
  direction,
  ariaLabel,
  ariaHidden,
}: {
  items: Item[];
  direction: "left" | "right";
  ariaLabel?: string;
  ariaHidden?: boolean;
}) {
  const scrollerRef = useRef<HTMLDivElement>(null);
  const [paused, setPaused] = useState(false);
  const dragRef = useRef({ active: false, x: 0, scrollLeft: 0 });
  const repeatedItems = [...items, ...items, ...items];

  useEffect(() => {
    const scroller = scrollerRef.current;
    if (!scroller) return;

    const half = scroller.scrollWidth / 3;
    if (direction === "right") {
      scroller.scrollLeft = half;
    }

    let frame = 0;
    const tick = () => {
      if (!paused && !dragRef.current.active && half > 0) {
        scroller.scrollLeft += direction === "left" ? 0.45 : -0.45;
        if (direction === "left" && scroller.scrollLeft >= half * 2) {
          scroller.scrollLeft = half;
        }
        if (direction === "right" && scroller.scrollLeft <= 0) {
          scroller.scrollLeft = half;
        }
      }
      frame = window.requestAnimationFrame(tick);
    };

    frame = window.requestAnimationFrame(tick);
    return () => window.cancelAnimationFrame(frame);
  }, [direction, paused, items.length]);

  return (
    <div
      ref={scrollerRef}
      className="overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden cursor-grab active:cursor-grabbing touch-pan-y"
      onPointerEnter={() => setPaused(true)}
      onPointerLeave={() => {
        dragRef.current.active = false;
        setPaused(false);
      }}
      onPointerDown={(event) => {
        const scroller = scrollerRef.current;
        if (!scroller) return;
        dragRef.current = {
          active: true,
          x: event.clientX,
          scrollLeft: scroller.scrollLeft,
        };
        setPaused(true);
        event.currentTarget.setPointerCapture(event.pointerId);
      }}
      onPointerMove={(event) => {
        const scroller = scrollerRef.current;
        if (!scroller || !dragRef.current.active) return;
        const delta = event.clientX - dragRef.current.x;
        scroller.scrollLeft = dragRef.current.scrollLeft - delta;
      }}
      onPointerUp={(event) => {
        dragRef.current.active = false;
        if (event.currentTarget.hasPointerCapture(event.pointerId)) {
          event.currentTarget.releasePointerCapture(event.pointerId);
        }
      }}
      onPointerCancel={() => {
        dragRef.current.active = false;
      }}
    >
      <ul
        className="flex w-max gap-3 px-1"
        aria-label={ariaLabel}
        aria-hidden={ariaHidden}
      >
        {repeatedItems.map((item, i) => (
          <li key={`${direction}-${item.name}-${i}`} className={cardClass}>
            {renderItem(item)}
          </li>
        ))}
      </ul>
    </div>
  );
}

const cardClass =
  "shrink-0 flex items-center justify-center min-w-[180px] h-[80px] px-5 rounded-[10px] bg-white border border-gray-100 shadow-[0_1px_4px_rgba(0,0,0,0.06)] select-none transition-[box-shadow] duration-300 hover:shadow-[0_4px_14px_rgba(0,0,0,0.11)]";

function renderItem(item: Item) {
  return item.src ? (
    <div className="relative pointer-events-none h-14 w-[140px]">
      <Image
        src={item.src}
        alt={item.name}
        fill
        sizes="140px"
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
