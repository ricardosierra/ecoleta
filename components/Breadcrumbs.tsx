import Link from "next/link";
import { cn } from "@/lib/cn";

type BreadcrumbItem = {
  label: string;
  href?: string;
};

type Props = {
  items: BreadcrumbItem[];
  tone?: "light" | "dark";
  className?: string;
};

export default function Breadcrumbs({ items, tone = "light", className }: Props) {
  const isDark = tone === "dark";

  return (
    <nav
      aria-label="Breadcrumb"
      className={cn(
        "text-xs font-medium",
        isDark ? "text-white/60" : "text-(--color-text-muted)",
        className
      )}
    >
      <ol className="flex flex-wrap items-center gap-2">
        {items.map((item, index) => {
          const isLast = index === items.length - 1;

          return (
            <li key={`${item.label}-${index}`} className="flex items-center gap-2">
              {index > 0 && <span aria-hidden="true">/</span>}
              {item.href && !isLast ? (
                <Link
                  href={item.href}
                  className={cn(
                    "underline-offset-4 hover:underline",
                    isDark ? "hover:text-(--color-accent)" : "hover:text-(--color-secondary)"
                  )}
                >
                  {item.label}
                </Link>
              ) : (
                <span
                  className={cn(
                    isLast && (isDark ? "text-white" : "text-(--color-bg-dark)")
                  )}
                  aria-current={isLast ? "page" : undefined}
                >
                  {item.label}
                </span>
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
