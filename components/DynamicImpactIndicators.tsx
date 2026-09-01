"use client";

import { useEffect, useState } from "react";
import Reveal from "./Reveal";
import {
  ImpactCarbonIcon,
  ImpactCarsIcon,
  ImpactEnergyIcon,
  ImpactPeopleIcon,
  ImpactTreesIcon,
  ImpactWaterIcon,
} from "./icons";

type IconComponent = (props: { width?: number; height?: number }) => React.ReactNode;

const iconByName: Record<string, IconComponent> = {
  ImpactPeopleIcon,
  ImpactCarbonIcon,
  ImpactEnergyIcon,
  ImpactTreesIcon,
  ImpactCarsIcon,
  ImpactWaterIcon,
};

// Espelho do seed da migration 012 — o que o site mostra quando a API não
// responde (build estático, banco fora do ar).
const fallbackIndicadores = [
  { icon: ImpactPeopleIcon, value: "300 Mil", label: "Pessoas impactadas" },
  { icon: ImpactCarbonIcon, value: "190 tCO₂e", label: "CO₂ evitado" },
  { icon: ImpactEnergyIcon, value: "300 Mil kWh", label: "Energia economizada" },
  { icon: ImpactTreesIcon, value: "1.300", label: "Árvores preservadas" },
  { icon: ImpactCarsIcon, value: "120", label: "Carros fora de circulação" },
  { icon: ImpactWaterIcon, value: "7 Mi", label: "Litros de água poupada" },
];

export function DynamicImpactIndicators() {
  const [indicadores, setIndicadores] = useState(fallbackIndicadores);

  useEffect(() => {
    fetch("/api/site/indicadores.php")
      .then(res => res.json())
      .then(data => {
        if (data.ok && Array.isArray(data.indicators) && data.indicators.length > 0) {
          const mapped = data.indicators
            .map((ind: { value: string; label: string; symbol_type: string; symbol_value: string }) => ({
              icon: iconByName[ind.symbol_value],
              value: ind.value,
              label: ind.label,
            }))
            .filter((ind: { icon?: IconComponent }) => ind.icon);
          if (mapped.length > 0) {
            setIndicadores(mapped);
          }
        }
      })
      .catch(() => {
        // Sem API (preview local, banco indisponível): fica o fallback.
      });
  }, []);

  return (
    <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 md:gap-6 lg:gap-7">
      {indicadores.map(({ icon: Icon, value, label }, i) => (
        <Reveal key={label} as="li" delay={i * 80}>
          <div className="flex min-h-[13.75rem] h-full flex-col items-center justify-center rounded-[10px] border border-(--color-border-dark) bg-(--color-bg-dark) px-6 py-7 text-center shadow-[var(--shadow-sm)] md:min-h-[14.5rem] md:px-8 md:py-8">
            <span className="mb-5 flex size-24 items-center justify-center rounded-full border-2 border-white/15 bg-white/[0.06] text-(--color-accent) shadow-[0_0_0_8px_var(--color-accent-soft),0_0_28px_var(--color-accent-soft)] md:size-28">
              <Icon width={58} height={58} />
            </span>
            <p className="text-3xl font-bold leading-none tracking-normal text-white sm:text-[2.15rem] md:text-[2.55rem]">
              {value}
            </p>
            <p className="mt-4 text-xs font-medium uppercase tracking-[0.18em] text-white/60 md:text-sm">
              {label}
            </p>
          </div>
        </Reveal>
      ))}
    </ul>
  );
}
