"use client";

import { Suspense, useEffect, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { DashboardGate, useDashboardAuth } from "@/components/DashboardGate";
import { DashboardAccessDenied } from "@/components/DashboardAccessDenied";
import { ArrowLeftIcon } from "@/components/icons";
import { isAdmin } from "@/lib/authz";
import { ClienteForm, type Client } from "@/components/ClienteForm";

export default function EditarClientePage() {
  return (
    <DashboardGate>
      <Suspense fallback={<div className="max-w-4xl mx-auto p-8 text-white">Carregando...</div>}>
        <EditarClienteMain />
      </Suspense>
    </DashboardGate>
  );
}

function EditarClienteMain() {
  const { user } = useDashboardAuth();
  const searchParams = useSearchParams();
  const clientId = searchParams.get("id");

  const [client, setClient] = useState<Client | null>(null);
  const [loading, setLoading] = useState(Boolean(clientId));
  const [fetchError, setFetchError] = useState("");

  const isUserAdmin = isAdmin(user);

  useEffect(() => {
    if (!isUserAdmin || !clientId) return;

    let isMounted = true;
    fetch(`/api/clients/index.php?id=${encodeURIComponent(clientId)}`)
      .then(res => res.json())
      .then(data => {
        if (!isMounted) return;
        if (data.ok && data.client) {
          setClient(data.client);
        } else {
          setFetchError(data.error || "Cliente não encontrado.");
        }
      })
      .catch(err => {
        if (!isMounted) return;
        setFetchError(err.message || "Erro ao carregar dados do cliente.");
      })
      .finally(() => {
        if (isMounted) setLoading(false);
      });

    return () => {
      isMounted = false;
    };
  }, [isUserAdmin, clientId]);

  if (!isUserAdmin) {
    return <DashboardAccessDenied area="a edição de clientes" />;
  }

  if (!clientId) {
    return (
      <div className="max-w-4xl mx-auto p-6 sm:p-8 text-white space-y-6">
        <Link
          href="/dashboard/clientes"
          className="inline-flex items-center gap-2 text-sm text-white/60 hover:text-white transition"
        >
          <ArrowLeftIcon className="w-4 h-4" />
          <span>Voltar para Clientes</span>
        </Link>
        <div className="rounded-2xl border border-red-500/30 bg-red-950/40 p-6 text-center space-y-4">
          <p className="text-xl font-semibold text-red-200">ID do cliente não informado</p>
          <p className="text-sm text-white/70">Nenhum cliente foi selecionado para edição.</p>
          <Link
            href="/dashboard/clientes"
            className="inline-block bg-[var(--color-accent)] text-[var(--color-bg-dark)] px-6 py-2 rounded-full font-semibold text-sm hover:opacity-90 transition"
          >
            Ir para listagem de clientes
          </Link>
        </div>
      </div>
    );
  }

  if (loading) {
    return (
      <div className="max-w-4xl mx-auto p-6 sm:p-8 text-white space-y-4">
        <div className="h-6 w-36 bg-white/10 rounded animate-pulse" />
        <div className="h-10 w-64 bg-white/10 rounded animate-pulse" />
        <div className="h-48 bg-white/5 rounded-2xl border border-[var(--color-border-dark)] animate-pulse" />
      </div>
    );
  }

  if (fetchError || !client) {
    return (
      <div className="max-w-4xl mx-auto p-6 sm:p-8 text-white space-y-6">
        <Link
          href="/dashboard/clientes"
          className="inline-flex items-center gap-2 text-sm text-white/60 hover:text-white transition"
        >
          <ArrowLeftIcon className="w-4 h-4" />
          <span>Voltar para Clientes</span>
        </Link>
        <div className="rounded-2xl border border-red-500/30 bg-red-950/40 p-6 text-center space-y-4">
          <p className="text-xl font-semibold text-red-200">Não foi possível carregar o cliente</p>
          <p className="text-sm text-white/70">{fetchError || "Cliente não localizado."}</p>
          <Link
            href="/dashboard/clientes"
            className="inline-block bg-[var(--color-accent)] text-[var(--color-bg-dark)] px-6 py-2 rounded-full font-semibold text-sm hover:opacity-90 transition"
          >
            Ir para listagem de clientes
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className="max-w-4xl mx-auto p-6 sm:p-8 space-y-6 text-white">
      <div>
        <Link
          href="/dashboard/clientes"
          className="inline-flex items-center gap-2 text-sm text-white/60 hover:text-white transition mb-4 group"
        >
          <ArrowLeftIcon className="w-4 h-4 transition-transform group-hover:-translate-x-0.5" />
          <span>Voltar para Clientes</span>
        </Link>
        <h1 className="text-3xl font-bold">Editar Cliente: {client.name}</h1>
        <p className="text-[var(--color-text-on-dark)] mt-2">
          Atualize os dados cadastrais, status e configurações de cobrança mensal deste cliente.
        </p>
      </div>

      <ClienteForm initialData={client} />
    </div>
  );
}
