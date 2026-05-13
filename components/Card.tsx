import { cn } from "@/lib/cn";
import type { HTMLAttributes, ReactNode } from "react";

type Tone = "white" | "dark" | "outline" | "soft";

const toneClasses: Record<Tone, string> = {
  white:
    "bg-white text-(--color-text) shadow-[var(--shadow-sm)] hover:shadow-[var(--shadow-md)]",
  dark: "bg-[#152a18] text-white border border-(--color-border-dark)",
  outline:
    "bg-transparent text-(--color-text) border border-(--color-border-light)",
  soft: "bg-(--color-bg-light) text-(--color-text)",
};

type Props = HTMLAttributes<HTMLDivElement> & {
  tone?: Tone;
  interactive?: boolean;
  children: ReactNode;
};

export default function Card({
  tone = "white",
  interactive = true,
  className,
  children,
  ...rest
}: Props) {
  return (
    <div
      {...rest}
      className={cn(
        "rounded-[10px] p-5 md:p-6 transition-[transform,box-shadow,border-color] duration-200",
        toneClasses[tone],
        interactive && "hover:-translate-y-1",
        className
      )}
    >
      {children}
    </div>
  );
}
