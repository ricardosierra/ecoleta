import { cn } from "@/lib/cn";
import type { ReactNode } from "react";

type Props = {
  eyebrow?: string;
  title: ReactNode;
  subtitle?: ReactNode;
  children?: ReactNode;
  className?: string;
};

export default function PageHero({
  eyebrow,
  title,
  subtitle,
  children,
  className,
}: Props) {
  return (
    <section
      className={cn(
        "relative bg-(--color-bg-dark) text-white overflow-hidden",
        className
      )}
    >
      <div
        aria-hidden
        className="absolute inset-0 opacity-[0.06] pointer-events-none"
        style={{
          backgroundImage:
            "radial-gradient(circle at 15% 0%, rgba(126,217,87,0.6) 0%, transparent 45%), radial-gradient(circle at 85% 100%, rgba(126,217,87,0.4) 0%, transparent 55%)",
        }}
      />
      <div className="container-page relative pt-12 pb-16 md:pt-20 md:pb-24">
        <div className="max-w-3xl">
          {eyebrow && (
            <p className="eyebrow flex items-center gap-2 mb-5">
              <span className="inline-block size-1.5 rounded-full bg-current" />
              {eyebrow}
            </p>
          )}
          <h1 className="hero-title text-balance">{title}</h1>
          {subtitle && (
            <p className="mt-6 text-base md:text-lg text-white/80 leading-relaxed max-w-2xl">
              {subtitle}
            </p>
          )}
          {children && <div className="mt-8">{children}</div>}
        </div>
      </div>
    </section>
  );
}
