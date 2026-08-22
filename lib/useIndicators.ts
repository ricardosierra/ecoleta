"use client";

import { useEffect, useState } from "react";

export type IndicatorMap = Record<
  string,
  { value: string; numeric_value?: number | null; label?: string }
>;

export function useIndicators(initialValues?: IndicatorMap) {
  const [indicators, setIndicators] = useState<IndicatorMap>(initialValues || {});

  useEffect(() => {
    let isMounted = true;
    fetch("/api/indicators/index.php")
      .then((res) => {
        if (!res.ok) throw new Error("Falha ao carregar indicadores");
        return res.json();
      })
      .then((data) => {
        if (isMounted && data.ok && data.map) {
          setIndicators(data.map);
        }
      })
      .catch(() => {
        // Fallback silencioso mantendo os valores iniciais
      });

    return () => {
      isMounted = false;
    };
  }, []);

  const get = (key: string, fallbackValue: string) => {
    return indicators[key]?.value || fallbackValue;
  };

  const getNumber = (key: string, fallbackNumber: number) => {
    const num = indicators[key]?.numeric_value;
    return typeof num === "number" && !isNaN(num) ? num : fallbackNumber;
  };

  return { indicators, get, getNumber };
}
