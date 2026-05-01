import { cn } from "@/lib/cn";

type Props = {
  className?: string;
  /** Color of the wordmark. Use "accent" on dark backgrounds. */
  variant?: "accent" | "dark" | "white";
};

const variantClasses = {
  accent: "text-(--color-accent)",
  dark: "text-(--color-bg-dark)",
  white: "text-white",
} as const;

export default function Logo({ className, variant = "accent" }: Props) {
  return (
    <span
      className={cn(
        "font-bold text-2xl md:text-[1.625rem] tracking-tight lowercase",
        variantClasses[variant],
        className
      )}
      aria-label="Ecoleta"
    >
      ecoleta
    </span>
  );
}
