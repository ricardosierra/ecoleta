"use client";

import { useEffect, useState } from "react";

type StatItem = {
  key: string;
  value: string;
  label: string;
};

const defaultStats: StatItem[] = [
  { key: "home_stat_rastreabilidade", value: "100%", label: "rastreabilidade" },
  { key: "home_stat_conformidade", value: "PNRS", label: "conformidade" },
  { key: "home_stat_documentacao", value: "MTR + CDF", label: "documentação" },
  { key: "home_stat_esg", value: "ESG", label: "aplicado" },
];

export default function HomeHeroStats() {
  const [stats, setStats] = useState<StatItem[]>(defaultStats);

  useEffect(() => {
    let isMounted = true;
    fetch("/api/indicators/index.php?category=home")
      .then((res) => res.json())
      .then((data) => {
        if (isMounted && data.ok && data.map) {
          setStats((prev) =>
            prev.map((item) => {
              const live = data.map[item.key];
              return live && live.value ? { ...item, value: live.value } : item;
            })
          );
        }
      })
      .catch(() => {});

    return () => {
      isMounted = false;
    };
  }, []);

  return (
    <div className="grid grid-cols-2 gap-4">
      {stats.map((stat) => (
        <div
          key={stat.key}
          className="rounded-[10px] bg-(--color-bg-dark) border border-(--color-border-dark) p-3 md:p-5"
        >
          <p className="text-lg md:text-[1.75rem] font-bold text-(--color-accent) leading-tight break-words min-w-0">
            {stat.value}
          </p>
          <p className="text-[0.65rem] md:text-xs uppercase tracking-widest text-white/50 mt-1.5 break-words min-w-0">
            {stat.label}
          </p>
        </div>
      ))}
    </div>
  );
}
