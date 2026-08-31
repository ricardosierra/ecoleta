"use client";

import { useEffect, useState } from "react";
import MetricCard from "./MetricCard";
import Reveal from "./Reveal";
import { CheckIcon, ScaleIcon } from "./icons";

const fallbackResultados = [
  { symbol: "↓R$", label: "Redução de custo operacional" },
  { symbol: "92%", label: "Menos envio ao aterro" },
  { symbol: <CheckIcon width={36} height={36} />, label: "Operação organizada e documentada" },
  { symbol: <ScaleIcon width={36} height={36} />, label: "Segurança jurídica e ambiental" },
  { symbol: "ESG", label: "Valor e reputação para sua marca" },
];

export function DynamicResults() {
  const [resultados, setResultados] = useState<{symbol: React.ReactNode, label: string}[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetch("/api/site/indicadores.php")
      .then(res => res.json())
      .then(data => {
        if (data.ok && data.indicators && data.indicators.length > 0) {
          const map = data.indicators.map((ind: {value: string, label: string, symbol_type: string, symbol_value: string}) => {
            let symbol: React.ReactNode = ind.value;
            if (ind.symbol_type === 'icon') {
              if (ind.symbol_value === 'CheckIcon') symbol = <CheckIcon width={36} height={36} />;
              if (ind.symbol_value === 'ScaleIcon') symbol = <ScaleIcon width={36} height={36} />;
            }
            return { symbol, label: ind.label };
          });
          setResultados(map);
        } else {
          setResultados(fallbackResultados);
        }
      })
      .catch(() => setResultados(fallbackResultados))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return null;

  return (
    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
      {resultados.map((r, i) => (
        <Reveal key={i} as="li" delay={i * 70}>
          <MetricCard symbol={r.symbol} label={r.label} className="h-full" />
        </Reveal>
      ))}
    </ul>
  );
}
