import { cn } from "@/lib/cn";
import type { ReactNode } from "react";

type Props = {
  symbol: ReactNode;
  label: string;
  className?: string;
};

export default function MetricCard({ symbol, label, className }: Props) {
  return (
    <div
      className={cn(
        "bg-white rounded-[10px] border border-(--color-border-light) p-6 flex flex-col items-center justify-start text-center transition-[transform,box-shadow] duration-200 hover:-translate-y-1 hover:shadow-[var(--shadow-sm)]",
        className
      )}
    >
      <div className="text-3xl md:text-4xl font-bold text-(--color-secondary) mb-3 flex items-center justify-center min-h-[3rem]">
        {symbol}
      </div>
      <p className="text-sm leading-snug text-(--color-text-muted)">{label}</p>
    </div>
  );
}
