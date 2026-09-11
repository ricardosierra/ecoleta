"use client";

import Link from "next/link";
import { DashboardGate, useDashboardAuth } from "@/components/DashboardGate";
import { DashboardAccessDenied } from "@/components/DashboardAccessDenied";
import { ArrowLeftIcon } from "@/components/icons";
import { isAdmin } from "@/lib/authz";
import { ClienteForm } from "@/components/ClienteForm";

export default function NovoClientePage() {
  return (
    <DashboardGate>
      <NovoClienteMain />
    </DashboardGate>
  );
}

function NovoClienteMain() {
  const { user } = useDashboardAuth();
  const isUserAdmin = isAdmin(user);

  if (!isUserAdmin) {
    return <DashboardAccessDenied area="o cadastro de clientes" />;
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
        <h1 className="text-3xl font-bold">Novo Cliente</h1>
        <p className="text-[var(--color-text-on-dark)] mt-2">
          Cadastre um novo cliente no sistema. Clientes com cobrança mensal habilitada serão sincronizados com o Asaas.
        </p>
      </div>

      <ClienteForm />
    </div>
  );
}
