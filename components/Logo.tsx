import Image from "next/image";
import { cn } from "@/lib/cn";

type Props = {
  className?: string;
  /**
   * "dark" → logo preta + verde (fundos claros)
   * "white" → logo toda branca (fundos escuros)
   */
  variant?: "dark" | "white";
  /** Height in pixels — controls actual rendered CSS height. */
  height?: number;
};

export default function Logo({ className, variant = "dark", height = 38 }: Props) {
  const src =
    variant === "white" ? "/ecoleta-logo-white.png" : "/ecoleta-logo-dark.png";

  return (
    <Image
      src={src}
      alt="Ecoleta"
      // intrinsic dimensions for aspect-ratio calculation
      width={1200}
      height={544}
      sizes="200px"
      style={{ height, width: "auto" }}
      className={cn(className)}
      priority
    />
  );
}
