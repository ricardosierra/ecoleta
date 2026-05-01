import Link from "next/link";
import { cn } from "@/lib/cn";
import type { ComponentPropsWithoutRef, ReactNode } from "react";

type Variant = "primary" | "secondary" | "ghost" | "dark" | "light-on-dark";
type Size = "default" | "sm" | "lg";

const variantClasses: Record<Variant, string> = {
  primary:
    "bg-(--color-accent) text-(--color-bg-dark) hover:bg-[#6BC547] active:bg-[#5fb53e] shadow-[0_8px_20px_-8px_rgba(126,217,87,0.6)]",
  secondary:
    "bg-(--color-secondary) text-white hover:bg-[#234828] active:bg-[#1c3a1f]",
  ghost:
    "bg-transparent text-(--color-text) border border-(--color-border-light) hover:border-(--color-secondary) hover:text-(--color-secondary)",
  dark: "bg-(--color-bg-dark) text-white hover:bg-black",
  "light-on-dark":
    "bg-white text-(--color-bg-dark) hover:bg-(--color-bg-light)",
};

const sizeClasses: Record<Size, string> = {
  default: "px-[35px] py-[15px] text-[0.9375rem]",
  sm: "px-6 py-3 text-sm",
  lg: "px-10 py-5 text-base",
};

type CommonProps = {
  variant?: Variant;
  size?: Size;
  className?: string;
  children: ReactNode;
  iconLeft?: ReactNode;
  iconRight?: ReactNode;
};

type AnchorProps = CommonProps &
  ComponentPropsWithoutRef<"a"> & {
    href: string;
    external?: boolean;
  };

type ButtonProps = CommonProps &
  ComponentPropsWithoutRef<"button"> & {
    href?: undefined;
  };

export type ButtonComponentProps = AnchorProps | ButtonProps;

const baseClasses =
  "inline-flex items-center justify-center gap-2 font-semibold rounded-full leading-none whitespace-nowrap transition-[transform,background-color,color,box-shadow] duration-200 ease-out hover:-translate-y-0.5 disabled:opacity-60 disabled:cursor-not-allowed disabled:hover:translate-y-0";

export default function Button(props: ButtonComponentProps) {
  const {
    variant = "primary",
    size = "default",
    className,
    children,
    iconLeft,
    iconRight,
    ...rest
  } = props;

  const composed = cn(
    baseClasses,
    variantClasses[variant],
    sizeClasses[size],
    className
  );

  const inner = (
    <>
      {iconLeft}
      <span>{children}</span>
      {iconRight}
    </>
  );

  if ("href" in props && props.href !== undefined) {
    const { href, external, ...anchorRest } = rest as AnchorProps;
    if (external || href.startsWith("http") || href.startsWith("mailto:")) {
      return (
        <a
          {...anchorRest}
          href={href}
          target={external ? "_blank" : anchorRest.target}
          rel={external ? "noopener noreferrer" : anchorRest.rel}
          className={composed}
        >
          {inner}
        </a>
      );
    }
    return (
      <Link href={href} {...(anchorRest as object)} className={composed}>
        {inner}
      </Link>
    );
  }

  return (
    <button {...(rest as ComponentPropsWithoutRef<"button">)} className={composed}>
      {inner}
    </button>
  );
}
