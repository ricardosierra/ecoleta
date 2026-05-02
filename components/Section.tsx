import { cn } from "@/lib/cn";
import type { HTMLAttributes, ReactNode } from "react";
import LetterReveal from "./LetterReveal";

type Tone = "dark" | "light" | "white" | "accent";

const toneClasses: Record<Tone, string> = {
  dark: "bg-(--color-bg-dark) text-white",
  light: "bg-(--color-bg-light) text-(--color-text)",
  white: "bg-white text-(--color-text)",
  accent: "bg-(--color-accent) text-(--color-bg-dark)",
};

type Props = HTMLAttributes<HTMLElement> & {
  tone?: Tone;
  /** Adds container with max-width 1140 */
  contained?: boolean;
  innerClassName?: string;
  children: ReactNode;
};

export default function Section({
  tone = "white",
  contained = true,
  className,
  innerClassName,
  children,
  ...rest
}: Props) {
  return (
    <section
      {...rest}
      className={cn(
        "section-py relative overflow-hidden",
        toneClasses[tone],
        className
      )}
    >
      {contained ? (
        <div className={cn("container-page", innerClassName)}>{children}</div>
      ) : (
        children
      )}
    </section>
  );
}

export function Eyebrow({
  children,
  className,
}: {
  children: ReactNode;
  className?: string;
}) {
  return (
    <p className={cn("eyebrow flex items-center gap-2", className)}>
      <span className="inline-block size-1.5 rounded-full bg-current" />
      {children}
    </p>
  );
}

export function SectionHeader({
  eyebrow,
  title,
  subtitle,
  align = "left",
  tone = "light",
  className,
}: {
  eyebrow?: string;
  title: ReactNode;
  subtitle?: ReactNode;
  align?: "left" | "center";
  tone?: "light" | "dark";
  className?: string;
}) {
  const subtitleColor =
    tone === "dark"
      ? "text-(--color-text-on-dark)"
      : "text-(--color-text-muted)";
  return (
    <header
      className={cn(
        "mb-10 md:mb-14 max-w-3xl",
        align === "center" && "mx-auto text-center",
        className
      )}
    >
      {eyebrow && (
        <Eyebrow className="mb-4">
          <LetterReveal text={eyebrow} charDelay={20} />
        </Eyebrow>
      )}
      <h2 className="section-title">
        {typeof title === "string" ? (
          <LetterReveal
            text={title}
            delay={eyebrow ? eyebrow.length * 20 + 100 : 0}
            charDelay={25}
          />
        ) : (
          title
        )}
      </h2>
      {subtitle && (
        <p className={cn("mt-4 text-base md:text-lg", subtitleColor)}>
          {subtitle}
        </p>
      )}
    </header>
  );
}
