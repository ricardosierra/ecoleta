import Image from "next/image";
import { cn } from "@/lib/cn";

type Props = {
  className?: string;
  /**
   * "dark" → logo preta + verde (fundos claros)
   * "white" → logo branca + verde via CSS filter (fundos escuros)
   */
  variant?: "dark" | "white";
  height?: number;
};

export default function Logo({ className, variant = "dark", height = 40 }: Props) {
  return (
    <Image
      src="/ecoleta-logo.png"
      alt="Ecoleta"
      height={height}
      width={0}
      sizes="200px"
      className={cn(
        "w-auto",
        variant === "white" && "brightness-0 invert",
        className
      )}
      priority
    />
  );
}
