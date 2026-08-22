"use client";

import { useEffect, useState, ComponentType, SVGProps } from "react";
import Reveal from "@/components/Reveal";
import {
  ImpactCarbonIcon,
  ImpactCarsIcon,
  ImpactEnergyIcon,
  ImpactPeopleIcon,
  ImpactTreesIcon,
  ImpactWaterIcon,
} from "@/components/icons";

type IconComponent = ComponentType<SVGProps<SVGSVGElement>>;

type IndicadorItem = {
  key: string;
  icon: IconComponent;
  value: string;
  label: string;
};

const initialImpacto: IndicadorItem[] = [
  { key: "esg_pessoas_impactadas", icon: ImpactPeopleIcon, value: "300 Mil", label: "Pessoas impactadas" },
  { key: "esg_co2_evitado", icon: ImpactCarbonIcon, value: "190 tCO₂e", label: "CO₂ evitado" },
  { key: "esg_energia_economizada", icon: ImpactEnergyIcon, value: "300 Mil kWh", label: "Energia economizada" },
  { key: "esg_arvores_preservadas", icon: ImpactTreesIcon, value: "1.300", label: "Árvores preservadas" },
  { key: "esg_carros_fora", icon: ImpactCarsIcon, value: "120", label: "Carros fora de circulação" },
  { key: "esg_agua_poupada", icon: ImpactWaterIcon, value: "7 Mi", label: "Litros de água poupada" },
];

export default function EsgIndicatorsList() {
  const [items, setItems] = useState<IndicadorItem[]>(initialImpacto);

  useEffect(() => {
    let isMounted = true;
    fetch("/api/indicators/index.php?category=esg")
      .then((res) => res.json())
      .then((data) => {
        if (isMounted && data.ok && data.map) {
          setItems((prev) =>
            prev.map((item) => {
              const live = data.map[item.key];
              if (!live || !live.value) return item;
              return {
                ...item,
                value: live.value,
                label: live.label || item.label,
              };
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
    <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 md:gap-6 lg:gap-7">
      {items.map(({ key, icon: Icon, value, label }, i) => (
        <Reveal key={key} as="li" delay={i * 80}>
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
