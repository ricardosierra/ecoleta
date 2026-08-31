"use client";

import { useEffect, useState } from "react";
import LogoCarousel from "./LogoCarousel";

const fallbackClientes = [
  { name: "Heineken", src: "/logos/heineken.png" },
  { name: "LIESA", src: "/logos/liesa.png" }
];

export function DynamicClients() {
  const [clientes, setClientes] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetch("/api/site/empresas.php")
      .then(res => res.json())
      .then(data => {
        if (data.ok && data.companies && data.companies.length > 0) {
          const active = data.companies.filter((c: any) => c.is_active);
          setClientes(active.map((c: any) => ({ name: c.name, src: c.logo_url })));
        } else {
          setClientes(fallbackClientes);
        }
      })
      .catch(() => setClientes(fallbackClientes))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return null;
  if (clientes.length === 0) return null;

  return <LogoCarousel items={clientes} />;
}
