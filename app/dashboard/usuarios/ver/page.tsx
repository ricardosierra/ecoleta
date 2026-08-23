"use client";

import { useEffect, useState, useCallback, Suspense, FormEvent } from "react";
import { DashboardGate, useDashboardAuth } from "@/components/DashboardGate";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { apiPostJson } from "@/lib/dashboard-api";

type Group = {
  id: number;
  name: string;
};

type User = {
  id: number;
  login: string;
  email: string | null;
  role: string;
  group_id: number | null;
  group_name: string | null;
  force_password_change?: boolean;
  created_at: string;
};

type ActivityLog = {
  id: number;
  user_id: number | null;
  target_login: string | null;
  action: string;
  description: string | null;
  performed_by_id: number | null;
  performed_by_login: string | null;
  ip_address: string;
  user_agent: string;
  created_at: string;
};

export default function ViewUsuarioPage() {
  return (
    <DashboardGate>
      <Suspense fallback={<div className="p-8 text-white">Carregando...</div>}>
        <UsuarioDetails />
      </Suspense>
    </DashboardGate>
  );
}

function UsuarioDetails() {
  const searchParams = useSearchParams();
  const id = searchParams.get("id");
  const router = useRouter();
  const { user: currentUser } = useDashboardAuth();
  
  const [user, setUser] = useState<User | null>(null);
  const [groups, setGroups] = useState<Group[]>([]);
  const [logs, setLogs] = useState<ActivityLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  // Modal Editar Usuário
  const [showEditModal, setShowEditModal] = useState(false);
  const [editLogin, setEditLogin] = useState("");
  const [editEmail, setEditEmail] = useState("");
  const [editRole, setEditRole] = useState("user");
  const [editGroupId, setEditGroupId] = useState<number | "">("");
  const [isEditing, setIsEditing] = useState(false);

  // Modal Gerar Senha
  const [showGenModal, setShowGenModal] = useState(false);
  const [isGenerating, setIsGenerating] = useState(false);
  const [genPasswordResult, setGenPasswordResult] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const [actionError, setActionError] = useState("");

  // Modal Excluir Usuário
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);

  const fetchUserData = useCallback(() => {
    if (!id) return;
    Promise.all([
      fetch(`/api/users/logs.php?user_id=${id}`),
      fetch("/api/groups/index.php"),
    ])
      .then(async ([resLogs, resGroups]) => {
        if (!resLogs.ok) throw new Error("Acesso negado ou usuário não encontrado.");
        const dataLogs = await resLogs.json();
        if (dataLogs.ok) {
          setUser(dataLogs.user);
          setLogs(dataLogs.logs || []);
        } else {
          throw new Error(dataLogs.error);
        }

        if (resGroups.ok) {
          const dataGroups = await resGroups.json();
          if (dataGroups.ok && Array.isArray(dataGroups.groups)) {
            setGroups(dataGroups.groups);
          }
        }
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [id]);

  useEffect(() => {
    fetchUserData();
  }, [fetchUserData]);

  const isRoot = currentUser?.role === "root";
  const isMaster = currentUser?.role === "master";

  const canEditUser = user
    ? isRoot || (isMaster && user.role === "user")
    : false;

  const canGeneratePassword = user
    ? isRoot || (isMaster && user.role === "user")
    : false;

  const canDeleteUser = user
    ? (isRoot && user.id !== currentUser?.id) || (isMaster && user.role === "user")
    : false;

  const openEditModal = () => {
    if (!user) return;
    setEditLogin(user.login);
    setEditEmail(user.email || "");
    setEditRole(user.role);
    setEditGroupId(user.group_id ?? (groups.length > 0 ? groups[0].id : ""));
    setActionError("");
    setShowEditModal(true);
  };

  const handleConfirmEdit = async (e: FormEvent) => {
    e.preventDefault();
    if (!user) return;

    if (editRole === "user" && !editGroupId) {
      setActionError("Selecione um grupo obrigatório para o usuário padrão.");
      return;
    }

    setIsEditing(true);
    setActionError("");

    try {
      const res = await apiPostJson("/api/users/edit.php", {
        user_id: user.id,
        login: editLogin,
        email: editEmail,
        role: editRole,
        group_id: editGroupId ? Number(editGroupId) : null,
      });
      const data = await res.json();

      if (res.ok && data.ok) {
        setShowEditModal(false);
        fetchUserData();
      } else {
        setActionError(data.error || "Erro ao atualizar usuário.");
      }
    } catch {
      setActionError("Erro de conexão ao editar usuário.");
    } finally {
      setIsEditing(false);
    }
  };

  const handleConfirmGeneratePassword = async () => {
    if (!user) return;
    setIsGenerating(true);
    setActionError("");

    try {
      const res = await apiPostJson("/api/users/generate_password.php", { user_id: user.id });
      const data = await res.json();

      if (res.ok && data.ok) {
        setGenPasswordResult(data.generated_password);
        setCopied(false);
        fetchUserData();
      } else {
        setActionError(data.error || "Erro ao gerar nova senha.");
      }
    } catch {
      setActionError("Erro de conexão ao gerar senha.");
    } finally {
      setIsGenerating(false);
    }
  };

  const handleCopyPassword = () => {
    if (genPasswordResult) {
      navigator.clipboard.writeText(genPasswordResult);
      setCopied(true);
      setTimeout(() => setCopied(false), 3000);
    }
  };

  const handleConfirmDelete = async () => {
    if (!user) return;
    setIsDeleting(true);
    setActionError("");

    try {
      const res = await apiPostJson("/api/users/delete.php", { user_id: user.id });
      const data = await res.json();

      if (res.ok && data.ok) {
        router.push("/dashboard/usuarios");
      } else {
        setActionError(data.error || "Erro ao excluir usuário.");
      }
    } catch {
      setActionError("Erro de conexão ao excluir usuário.");
    } finally {
      setIsDeleting(false);
    }
  };

  const renderActionBadge = (action: string) => {
    switch (action) {
      case "login":
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
            <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" />
            Login
          </span>
        );
      case "logout":
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-gray-500/20 text-gray-300 border border-gray-500/30">
            <span className="h-1.5 w-1.5 rounded-full bg-gray-400" />
            Logout
          </span>
        );
      case "change_password":
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-500/20 text-blue-300 border border-blue-500/30">
            <span className="h-1.5 w-1.5 rounded-full bg-blue-400" />
            Troca de Senha
          </span>
        );
      case "reset_password":
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-500/20 text-amber-300 border border-amber-500/30">
            <span className="h-1.5 w-1.5 rounded-full bg-amber-400" />
            Senha Redefinida
          </span>
        );
      case "create_user":
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-purple-500/20 text-purple-300 border border-purple-500/30">
            <span className="h-1.5 w-1.5 rounded-full bg-purple-400" />
            Conta Criada
          </span>
        );
      case "edit_user":
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-500/20 text-blue-300 border border-blue-500/30">
            <span className="h-1.5 w-1.5 rounded-full bg-blue-400" />
            Edição de Cadastro
          </span>
        );
      case "delete_user":
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-red-500/20 text-red-300 border border-red-500/30">
            <span className="h-1.5 w-1.5 rounded-full bg-red-400" />
            Exclusão
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-white/10 text-white/80 border border-white/15">
            {action}
          </span>
        );
    }
  };

  if (loading) return <div className="p-8 text-white">Carregando histórico...</div>;
  if (error) return <div className="p-8 text-red-400">{error}</div>;
  if (!user) return <div className="p-8 text-white">Usuário não encontrado.</div>;

  return (
    <div className="p-4 sm:p-8 max-w-6xl mx-auto h-full overflow-y-auto">
      {/* Botão voltar */}
      <Link 
        href="/dashboard/usuarios" 
        className="text-[var(--color-accent)] text-sm font-medium hover:underline inline-flex items-center gap-2 mb-6"
      >
        &larr; Voltar para Usuários
      </Link>

      {/* Card de detalhes do usuário */}
      <div className="bg-[rgba(255,255,255,0.03)] border border-[var(--color-border-dark)] rounded-3xl p-6 sm:p-8 shadow-2xl mb-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
        <div>
          <div className="flex items-center gap-3 mb-2 flex-wrap">
            <h1 className="text-3xl font-bold text-white">{user.login}</h1>
            <span className={`px-3 py-1 rounded-full text-xs font-semibold uppercase tracking-wider ${
              user.role === 'root' 
                ? 'bg-red-500/20 text-red-300 border border-red-500/30' 
                : user.role === 'master' 
                ? 'bg-blue-500/20 text-blue-300 border border-blue-500/30' 
                : 'bg-white/10 text-white/90 border border-white/15'
            }`}>
              {user.role}
            </span>
            {user.group_name && (
              <span className="px-3 py-1 rounded-full text-xs font-semibold bg-[var(--color-accent-soft)] text-[var(--color-accent)] border border-[var(--color-accent)]/20">
                Grupo: {user.group_name}
              </span>
            )}
            {user.force_password_change && (
              <span className="px-3 py-1 rounded-full text-xs font-semibold bg-amber-500/20 text-amber-300 border border-amber-500/30">
                Pendente troca de senha
              </span>
            )}
          </div>
          <p className="text-sm text-[var(--color-text-on-dark)]">
            E-mail: <span className="text-white font-medium">{user.email || 'Não informado'}</span>
          </p>
          <p className="text-xs text-white/60 mt-1">
            Cadastrado em: {new Date(user.created_at).toLocaleString('pt-BR')}
          </p>
        </div>

        {/* Ações de administrador */}
        <div className="flex items-center gap-3 flex-wrap">
          {canEditUser && (
            <button
              onClick={openEditModal}
              className="bg-blue-500/15 hover:bg-blue-500/25 text-blue-300 border border-blue-500/40 px-5 py-2.5 rounded-full text-xs sm:text-sm font-semibold transition-colors flex items-center gap-2 cursor-pointer"
            >
              ✏️ Editar Cadastro
            </button>
          )}

          {canGeneratePassword && (
            <button
              onClick={() => {
                setShowGenModal(true);
                setActionError("");
                setGenPasswordResult(null);
              }}
              className="bg-amber-500/15 hover:bg-amber-500/25 text-amber-300 border border-amber-500/40 px-5 py-2.5 rounded-full text-xs sm:text-sm font-semibold transition-colors flex items-center gap-2 cursor-pointer"
            >
              🔑 Gerar Nova Senha
            </button>
          )}

          {canDeleteUser && (
            <button
              onClick={() => {
                setShowDeleteModal(true);
                setActionError("");
              }}
              className="bg-red-500/15 hover:bg-red-500/25 text-red-300 border border-red-500/40 px-5 py-2.5 rounded-full text-xs sm:text-sm font-semibold transition-colors flex items-center gap-2 cursor-pointer"
            >
              🗑️ Excluir Usuário
            </button>
          )}
        </div>
      </div>

      {/* Tabela de histórico de auditoria */}
      <div className="bg-[rgba(255,255,255,0.03)] border border-[var(--color-border-dark)] rounded-3xl overflow-hidden shadow-2xl">
        <div className="px-6 py-5 border-b border-[var(--color-border-dark)] bg-black/30 flex justify-between items-center">
          <div>
            <h2 className="text-lg font-semibold text-white">Histórico de Atividades e Acessos</h2>
            <p className="text-xs text-white/60 mt-0.5">Logs de auditoria e registros de segurança (últimos 100 eventos)</p>
          </div>
          <span className="text-xs text-[var(--color-accent)] bg-[var(--color-accent-soft)] px-3 py-1 rounded-full border border-[var(--color-accent)]/20">
            {logs.length} {logs.length === 1 ? 'evento' : 'eventos'}
          </span>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm text-white/80">
            <thead className="bg-black/40 text-white border-b border-[var(--color-border-dark)] text-xs uppercase tracking-wider">
              <tr>
                <th className="px-6 py-4 font-semibold">Data / Hora</th>
                <th className="px-6 py-4 font-semibold">Ação / Evento</th>
                <th className="px-6 py-4 font-semibold">Detalhes / Responsável</th>
                <th className="px-6 py-4 font-semibold">Endereço IP</th>
                <th className="px-6 py-4 font-semibold">Dispositivo / Agente</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[var(--color-border-dark)]">
              {logs.map((log) => (
                <tr key={log.id} className="hover:bg-white/5 transition-colors">
                  <td className="px-6 py-4 font-medium text-white whitespace-nowrap text-xs">
                    {new Date(log.created_at).toLocaleString("pt-BR")}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    {renderActionBadge(log.action)}
                  </td>
                  <td className="px-6 py-4 text-xs">
                    <div className="font-medium text-white/90">
                      {log.description || "—"}
                    </div>
                    {log.performed_by_login && log.performed_by_login !== user.login && (
                      <div className="text-[11px] text-amber-300/80 mt-0.5">
                        Executor: <span className="font-mono">{log.performed_by_login}</span>
                      </div>
                    )}
                  </td>
                  <td className="px-6 py-4 text-xs font-mono text-white/70 whitespace-nowrap">
                    {log.ip_address}
                  </td>
                  <td className="px-6 py-4 text-xs max-w-xs truncate text-white/60" title={log.user_agent}>
                    {log.user_agent}
                  </td>
                </tr>
              ))}
              {logs.length === 0 && (
                <tr>
                  <td colSpan={5} className="px-6 py-8 text-center text-white/60">
                    Nenhum registro de atividade encontrado para este usuário.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Modal: Editar Usuário */}
      {showEditModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm animate-in fade-in duration-150">
          <form onSubmit={handleConfirmEdit} className="w-full max-w-lg bg-[#0D1F0F] border border-[var(--color-border-dark)] rounded-3xl p-6 shadow-2xl">
            <div className="flex items-center gap-3 mb-4 text-blue-400">
              <span className="text-2xl">✏️</span>
              <h3 className="text-xl font-bold text-white">Editar Usuário</h3>
            </div>
            <p className="text-sm text-[var(--color-text-on-dark)] mb-6">
              Atualize as informações do usuário <strong className="text-white">{user.login}</strong>.
            </p>

            <div className="space-y-4 mb-6">
              <div>
                <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)] mb-1">
                  Login
                </label>
                <input
                  value={editLogin}
                  onChange={(e) => setEditLogin(e.target.value)}
                  required
                  className="w-full rounded-xl border border-[var(--color-border-dark)] bg-black/40 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)] mb-1">
                  E-mail
                </label>
                <input
                  type="email"
                  value={editEmail}
                  onChange={(e) => setEditEmail(e.target.value)}
                  required
                  className="w-full rounded-xl border border-[var(--color-border-dark)] bg-black/40 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
                />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {isRoot && (
                  <div>
                    <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)] mb-1">
                      Nível de Acesso
                    </label>
                    <select
                      value={editRole}
                      onChange={(e) => setEditRole(e.target.value)}
                      className="w-full rounded-xl border border-[var(--color-border-dark)] bg-black/40 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
                    >
                      <option value="user" className="bg-[#0D1F0F] text-white">Usuário Padrão</option>
                      <option value="master" className="bg-[#0D1F0F] text-white">Master</option>
                      <option value="root" className="bg-[#0D1F0F] text-white">Root</option>
                    </select>
                  </div>
                )}

                <div className={isRoot ? "" : "sm:col-span-2"}>
                  <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)] mb-1">
                    Grupo (Power BI)
                  </label>
                  <select
                    value={editGroupId}
                    onChange={(e) => setEditGroupId(e.target.value ? Number(e.target.value) : "")}
                    required={editRole === "user"}
                    className="w-full rounded-xl border border-[var(--color-border-dark)] bg-black/40 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
                  >
                    {editRole !== "user" && <option value="" className="bg-[#0D1F0F] text-white">Nenhum (Todos/Admin)</option>}
                    {groups.map((g) => (
                      <option key={g.id} value={g.id} className="bg-[#0D1F0F] text-white">
                        {g.name}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              {canGeneratePassword && (
                <div className="pt-3 border-t border-white/10 flex items-center justify-between gap-3">
                  <div>
                    <p className="text-xs font-semibold text-white">Redefinição de Acesso</p>
                    <p className="text-[11px] text-white/50">Precisa enviar uma nova credencial para este usuário?</p>
                  </div>
                  <button
                    type="button"
                    onClick={() => {
                      setShowEditModal(false);
                      setShowGenModal(true);
                      setActionError("");
                      setGenPasswordResult(null);
                    }}
                    className="px-3.5 py-1.5 rounded-full text-xs font-semibold border border-amber-500/40 text-amber-300 hover:bg-amber-500/20 hover:text-white transition-colors cursor-pointer whitespace-nowrap"
                  >
                    🔑 Gerar Nova Senha
                  </button>
                </div>
              )}
            </div>

            {actionError && (
              <p className="text-xs text-red-300 mb-4 p-3 bg-red-950/60 border border-red-500/40 rounded-xl">{actionError}</p>
            )}

            <div className="flex justify-end gap-3">
              <button
                type="button"
                onClick={() => { setShowEditModal(false); setActionError(""); }}
                disabled={isEditing}
                className="px-5 py-2.5 rounded-full text-sm text-white/70 hover:text-white hover:bg-white/5 transition-colors cursor-pointer"
              >
                Cancelar
              </button>
              <button
                type="submit"
                disabled={isEditing}
                className="bg-blue-600 hover:bg-blue-500 text-white px-6 py-2.5 rounded-full text-sm font-semibold transition-opacity disabled:opacity-50 cursor-pointer shadow-lg"
              >
                {isEditing ? "Salvando..." : "Salvar Alterações"}
              </button>
            </div>
          </form>
        </div>
      )}

      {/* Modal: Gerar Senha */}
      {showGenModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
          <div className="w-full max-w-md bg-[#0D1F0F] border border-[var(--color-border-dark)] rounded-3xl p-6 shadow-2xl animate-in fade-in zoom-in-95 duration-200">
            {!genPasswordResult ? (
              <>
                <div className="flex items-center gap-3 mb-4 text-amber-400">
                  <span className="text-2xl">🔑</span>
                  <h3 className="text-xl font-bold text-white">Gerar Nova Senha</h3>
                </div>
                <p className="text-sm text-[var(--color-text-on-dark)] mb-4">
                  Deseja gerar uma nova senha temporária para <strong className="text-white">{user.login}</strong>?
                </p>
                <p className="text-xs text-white/60 mb-6 bg-white/5 p-3 rounded-xl border border-white/10">
                  ⚠️ A senha atual será invalidada imediatamente e o usuário precisará cadastrar uma nova no próximo acesso.
                </p>

                {actionError && (
                  <p className="text-xs text-red-300 mb-4 p-3 bg-red-950/60 border border-red-500/40 rounded-xl">{actionError}</p>
                )}

                <div className="flex justify-end gap-3">
                  <button
                    onClick={() => { setShowGenModal(false); setActionError(""); }}
                    disabled={isGenerating}
                    className="px-5 py-2.5 rounded-full text-sm text-white/70 hover:text-white hover:bg-white/5 transition-colors cursor-pointer"
                  >
                    Cancelar
                  </button>
                  <button
                    onClick={handleConfirmGeneratePassword}
                    disabled={isGenerating}
                    className="bg-amber-500 hover:bg-amber-400 text-black px-6 py-2.5 rounded-full text-sm font-semibold transition-opacity disabled:opacity-50 cursor-pointer shadow-lg"
                  >
                    {isGenerating ? "Gerando..." : "Confirmar e Gerar"}
                  </button>
                </div>
              </>
            ) : (
              <>
                <div className="flex items-center gap-3 mb-3 text-emerald-400">
                  <span className="text-2xl">✓</span>
                  <h3 className="text-xl font-bold text-white">Nova Senha Gerada!</h3>
                </div>
                <p className="text-sm text-[var(--color-text-on-dark)] mb-4">
                  A nova senha temporária para <strong className="text-white">{user.login}</strong> foi criada com sucesso:
                </p>

                <div className="mb-4 p-4 rounded-2xl bg-black/40 border border-[var(--color-accent)]/40 flex items-center justify-between">
                  <code className="text-lg font-mono font-bold text-[var(--color-accent)] select-all tracking-wider">
                    {genPasswordResult}
                  </code>
                  <button
                    onClick={handleCopyPassword}
                    className={`px-3.5 py-1.5 rounded-full text-xs font-semibold transition-all cursor-pointer ${
                      copied 
                        ? 'bg-emerald-500 text-black' 
                        : 'bg-white/10 hover:bg-white/20 text-white'
                    }`}
                  >
                    {copied ? "✓ Copiado!" : "Copiar"}
                  </button>
                </div>

                <p className="text-xs text-white/60 mb-6">
                  Compartilhe esta senha com o usuário de forma segura. O evento foi registrado no histórico acima.
                </p>

                <div className="flex justify-end">
                  <button
                    onClick={() => {
                      setShowGenModal(false);
                      setGenPasswordResult(null);
                    }}
                    className="bg-[var(--color-accent)] text-black px-6 py-2.5 rounded-full text-sm font-semibold hover:opacity-90 transition-opacity cursor-pointer"
                  >
                    Concluir
                  </button>
                </div>
              </>
            )}
          </div>
        </div>
      )}

      {/* Modal: Excluir Usuário */}
      {showDeleteModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
          <div className="w-full max-w-md bg-[#0D1F0F] border border-red-500/30 rounded-3xl p-6 shadow-2xl animate-in fade-in zoom-in-95 duration-200">
            <div className="flex items-center gap-3 mb-4 text-red-400">
              <span className="text-2xl">🗑️</span>
              <h3 className="text-xl font-bold text-white">Excluir Usuário</h3>
            </div>
            <p className="text-sm text-[var(--color-text-on-dark)] mb-4">
              Tem certeza que deseja excluir permanentemente o usuário <strong className="text-white">{user.login}</strong>?
            </p>
            <p className="text-xs text-red-300/80 mb-6 bg-red-950/40 p-3 rounded-xl border border-red-500/20">
              ⚠️ Esta ação removerá a conta permanentemente. O evento de exclusão será registrado no histórico.
            </p>

            {actionError && (
              <p className="text-xs text-red-300 mb-4 p-3 bg-red-950/60 border border-red-500/40 rounded-xl">{actionError}</p>
            )}

            <div className="flex justify-end gap-3">
              <button
                onClick={() => { setShowDeleteModal(false); setActionError(""); }}
                disabled={isDeleting}
                className="px-5 py-2.5 rounded-full text-sm text-white/70 hover:text-white hover:bg-white/5 transition-colors cursor-pointer"
              >
                Cancelar
              </button>
              <button
                onClick={handleConfirmDelete}
                disabled={isDeleting}
                className="bg-red-600 hover:bg-red-500 text-white px-6 py-2.5 rounded-full text-sm font-semibold transition-opacity disabled:opacity-50 cursor-pointer shadow-lg"
              >
                {isDeleting ? "Excluindo..." : "Confirmar Exclusão"}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

