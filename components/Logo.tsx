import Image from "next/image";
import { cn } from "@/lib/cn";

type Props = {
  className?: string;
  /**
   * "dark" → logo preta + verde (fundos claros)
   * "white" → logo branca + verde via CSS filter (fundos escuros)
   */
  variant?: "dark" | "white";
  /** Height in pixels — controls actual rendered CSS height. */
  height?: number;
};

export default function Logo({ className, variant = "dark", height = 32 }: Props) {
  return (
    <Image
      src="/ecoleta-logo.png"
      alt="Ecoleta"
      // intrinsic dimensions for aspect-ratio calculation
      width={955}
      height={432}
      sizes="200px"
      style={{ height, width: "auto" }}
      className={cn(
        variant === "white" && "brightness-0 invert",
        className
      )}
      priority
    />
  );
}
