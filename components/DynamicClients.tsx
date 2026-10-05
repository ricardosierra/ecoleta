"use client";

import { useEffect, useState } from "react";
import LogoCarousel from "./LogoCarousel";

const fallbackClientes = [
  { name: "Heineken", src: "/logos/heineken.png" },
  { name: "LIESA", src: "/logos/liesa.png" }
];

type Company = { is_active: number; name: string; logo_url: string };

export function DynamicClients() {
  const [clientes, setClientes] = useState<{name: string, src: string}[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetch("/api/site/empresas.php")
      .then(res => res.json())
      .then(data => {
        if (!data.ok || !Array.isArray(data.companies)) {
          // Resposta de erro do servidor: a lista de reserva evita uma home sem clientes.
          setClientes(fallbackClientes);
          return;
        }
        // A API só entrega empresa ativa ao visitante, mas o admin logado recebe
        // a lista do painel (o cookie de sessão vai em toda chamada a /api/), e a
        // tela pública não pode mostrar o que ele mesmo tirou do site.
        // Lista vazia é decisão do operador (tirou todas do site), não falha:
        // por isso não cai na lista de reserva e o carrossel simplesmente some.
        const active = (data.companies as Company[]).filter((c) => c.is_active);
        setClientes(active.map((c) => ({ name: c.name, src: c.logo_url })));
      })
      .catch(() => setClientes(fallbackClientes))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return null;
  if (clientes.length === 0) return null;

  return <LogoCarousel items={clientes} />;
}
