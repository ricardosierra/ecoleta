"use client";

import { useEffect, useState, ReactNode } from "react";
import MetricCard from "@/components/MetricCard";
import Reveal from "@/components/Reveal";
import { CheckIcon, ScaleIcon } from "@/components/icons";

type ResultadoItem = {
  key: string;
  symbol: ReactNode;
  label: string;
  defaultSymbolText?: string;
};

const initialResultados: ResultadoItem[] = [
  { key: "home_reducao_custo", symbol: "↓R$", defaultSymbolText: "↓R$", label: "Redução de custo operacional" },
  { key: "home_menos_aterro", symbol: "92%", defaultSymbolText: "92%", label: "Menos envio ao aterro" },
  { key: "home_operacao_organizada", symbol: <CheckIcon width={36} height={36} />, defaultSymbolText: "100%", label: "Operação organizada e documentada" },
  { key: "home_seguranca_juridica", symbol: <ScaleIcon width={36} height={36} />, defaultSymbolText: "100%", label: "Segurança jurídica e ambiental" },
  { key: "home_esg_valor", symbol: "ESG", defaultSymbolText: "ESG", label: "Valor e reputação para sua marca" },
];

export default function HomeResultadosList() {
  const [items, setItems] = useState<ResultadoItem[]>(initialResultados);

  useEffect(() => {
    let isMounted = true;
    fetch("/api/indicators/index.php?category=home")
      .then((res) => res.json())
      .then((data) => {
        if (isMounted && data.ok && data.map) {
          setItems((prev) =>
            prev.map((item) => {
              const live = data.map[item.key];
              if (!live || !live.value) return item;

              // Se o item tem um ícone especial fixo e o valor for percentual/numérico alterado, podemos exibir texto ou manter
              if (item.key === "home_operacao_organizada" || item.key === "home_seguranca_juridica") {
                // Se o usuário digitou algo diferente do padrão como "100%" ou ícone, reflete
                if (live.value !== "100%" && live.value !== "") {
                  return { ...item, symbol: live.value, label: live.label || item.label };
                }
                return { ...item, label: live.label || item.label };
              }

              return {
                ...item,
                symbol: live.value,
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
    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
      {items.map((r, i) => (
        <Reveal key={r.key} as="li" delay={i * 70}>
          <MetricCard symbol={r.symbol} label={r.label} className="h-full" />
        </Reveal>
      ))}
    </ul>
  );
}
