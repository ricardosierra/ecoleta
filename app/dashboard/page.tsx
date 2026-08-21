"use client";

import { useEffect, useState } from "react";
import { DashboardGate, useDashboardAuth } from "@/components/DashboardGate";
import { PowerBIViewer } from "@/components/PowerBIViewer";
import Link from "next/link";

type Group = {
  id: number;
  name: string;
  powerbi_url: string | null;
  users_count: number;
};

export default function DashboardPage() {
  return (
    <DashboardGate>
      <DashboardMain />
    </DashboardGate>
  );
}

function DashboardMain() {
  const { user } = useDashboardAuth();
  const [groups, setGroups] = useState<Group[]>([]);
  const [selectedGroupId, setSelectedGroupId] = useState<number | null>(null);
  const isAdmin = user?.role === "root" || user?.role === "master";
  const [loadingGroups, setLoadingGroups] = useState(isAdmin);

  useEffect(() => {
    if (user?.role !== "root" && user?.role !== "master") return;
    let isMounted = true;
    fetch("/api/groups/index.php")
      .then((res) => res.json())
      .then((data) => {
        if (isMounted && data.ok && Array.isArray(data.groups) && data.groups.length > 0) {
          setGroups(data.groups);
          // Seleciona o grupo do usuário se houver, ou o primeiro da lista
          setSelectedGroupId((prev) => {
            if (prev !== null) return prev;
            if (user?.group_id && data.groups.some((g: Group) => g.id === user.group_id)) {
              return user.group_id;
            }
            return data.groups[0].id;
          });
        }
      })
      .catch(() => {})
      .finally(() => {
        if (isMounted) setLoadingGroups(false);
      });

    return () => {
      isMounted = false;
    };
  }, [user?.role, user?.group_id]);

  // Usuário padrão: visualiza diretamente o Power BI do seu grupo atribuído
  if (!isAdmin) {
    return (
      <div className="flex h-full flex-col">
        {user?.group_name && (
          <div className="bg-black/30 border-b border-[var(--color-border-dark)] px-4 py-2 flex items-center justify-between text-xs text-white/80">
            <span className="flex items-center gap-2">
              <span className="h-2 w-2 rounded-full bg-[var(--color-accent)]" />
              Grupo: <strong className="text-white">{user.group_name}</strong>
            </span>
          </div>
        )}
        <div className="flex-1 min-h-0">
          <PowerBIViewer url={user?.group_powerbi_url} groupName={user?.group_name} />
        </div>
      </div>
    );
  }

  // Usuários Root / Master: possuem seletor de grupos para alternar e inspecionar painéis
  const currentGroup = groups.find((g) => g.id === selectedGroupId) || groups[0];

  return (
    <div className="flex h-full flex-col">
      {/* Barra de seleção de grupo para administradores */}
      <div className="bg-black/40 border-b border-[var(--color-border-dark)] px-4 sm:px-6 py-2.5 flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2 sm:gap-3 flex-wrap">
          <span className="text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)]">
            Painel do Grupo:
          </span>
          {loadingGroups ? (
            <span className="text-xs text-white/60">Carregando grupos...</span>
          ) : (
            <div className="flex items-center gap-1.5 flex-wrap">
              {groups.map((group) => {
                const isSelected = (currentGroup?.id === group.id);
                return (
                  <button
                    key={group.id}
                    onClick={() => setSelectedGroupId(group.id)}
                    className={`text-xs font-medium px-3.5 py-1.5 rounded-full transition-all cursor-pointer ${
                      isSelected
                        ? "bg-[var(--color-accent)] text-black font-semibold shadow-sm"
                        : "bg-white/5 hover:bg-white/10 text-white/80 hover:text-white border border-white/10"
                    }`}
                  >
                    {group.name}
                  </button>
                );
              })}
            </div>
          )}
        </div>

        <div className="flex items-center gap-3">
          <Link
            href="/dashboard/grupos"
            className="text-xs text-[var(--color-accent)] hover:underline flex items-center gap-1"
          >
            ⚙️ Gerenciar Grupos &rarr;
          </Link>
        </div>
      </div>

      {/* Visualizador do Power BI */}
      <div className="flex-1 min-h-0">
        <PowerBIViewer
          url={currentGroup?.powerbi_url}
          groupName={currentGroup?.name}
        />
      </div>
    </div>
  );
}

