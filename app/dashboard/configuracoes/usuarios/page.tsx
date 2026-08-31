"use client";

import { useEffect, useState, useCallback, FormEvent } from "react";
import { useDashboardAuth } from "@/components/DashboardGate";
import { DashboardAccessDenied } from "@/components/DashboardAccessDenied";
import Link from "next/link";
import { apiPostJson } from "@/lib/dashboard-api";
import {
  ROLE_LABELS,
  assignableRolesOnCreate,
  assignableRolesOnEdit,
  canDeleteUser,
  canEditUser,
  canGeneratePassword,
  canManageUsers,
  requiresGroup,
} from "@/lib/authz";

type Group = {
  id: number;
  name: string;
  powerbi_url?: string | null;
  users_count?: number;
};

type User = {
  id: number;
  login: string;
  email: string | null;
  role: string;
  group_id: number | null;
  group_name: string | null;
  force_password_change: boolean;
  created_at: string;
};

export default function UsuariosPage() {
  return <UsuariosList />;
}

function UsuariosList() {
  const { user: currentUser } = useDashboardAuth();
  const [users, setUsers] = useState<User[]>([]);
  const [groups, setGroups] = useState<Group[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [isCreating, setIsCreating] = useState(false);

  // Form states: Criar Usuário
  const [login, setLogin] = useState("");
  const [email, setEmail] = useState("");
  const [role, setRole] = useState("user");
  const [groupId, setGroupId] = useState<number | "">("");
  const [successMsg, setSuccessMsg] = useState("");

  // Modal: Editar Usuário
  const [editTarget, setEditTarget] = useState<User | null>(null);
  const [editLogin, setEditLogin] = useState("");
  const [editEmail, setEditEmail] = useState("");
  const [editRole, setEditRole] = useState("user");
  const [editGroupId, setEditGroupId] = useState<number | "">("");
  const [isEditing, setIsEditing] = useState(false);

  // Modal: Gerar Senha
  const [genTarget, setGenTarget] = useState<User | null>(null);
  const [isGenerating, setIsGenerating] = useState(false);
  const [generatedPasswordResult, setGeneratedPasswordResult] = useState<{ login: string; password: string } | null>(null);
  const [copied, setCopied] = useState(false);
  const [actionError, setActionError] = useState("");

  // Modal: Excluir Usuário
  const [deleteTarget, setDeleteTarget] = useState<User | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);

  const canManage = canManageUsers(currentUser);

  const fetchUsersAndGroups = useCallback(() => {
    // Sem permissão a tela nem chega a pedir os dados: o 403 da API é a segunda
    // linha de defesa, não a primeira.
    if (!canManage) {
      return;
    }

    let isMounted = true;
    Promise.all([
      fetch("/api/users/index.php"),
      fetch("/api/groups/index.php"),
    ])
      .then(async ([resUsers, resGroups]) => {
        if (!resUsers.ok) throw new Error("Acesso negado.");
        const dataUsers = await resUsers.json();
        if (isMounted && dataUsers.ok) setUsers(dataUsers.users);

        if (resGroups.ok) {
          const dataGroups = await resGroups.json();
          if (isMounted && dataGroups.ok && Array.isArray(dataGroups.groups)) {
            setGroups(dataGroups.groups);
            setGroupId((prev) => (prev === "" && dataGroups.groups.length > 0 ? dataGroups.groups[0].id : prev));
          }
        }
      })
      .catch((e: unknown) => {
        if (isMounted) {
          if (e instanceof Error) {
            setError(e.message);
          } else {
            setError("Erro desconhecido ao carregar dados.");
          }
        }
      })
      .finally(() => {
        if (isMounted) setLoading(false);
      });

    return () => {
      isMounted = false;
    };
  }, [canManage]);

  useEffect(() => {
    const cancel = fetchUsersAndGroups();
    return () => {
      if (cancel) cancel();
    };
  }, [fetchUsersAndGroups]);

  const creatableRoles = assignableRolesOnCreate(currentUser);
  const editableRoles = assignableRolesOnEdit(currentUser, editTarget ?? {});

  const handleCreate = async (e: FormEvent) => {
    e.preventDefault();
    setError("");
    setSuccessMsg("");

    if (requiresGroup(role) && !groupId) {
      setError("Selecione um grupo obrigatório para o usuário padrão.");
      return;
    }
    
    try {
      const res = await apiPostJson("/api/users/index.php", {
        login, 
        email, 
        role,
        group_id: groupId ? Number(groupId) : null
      });
      const data = await res.json();
      if (res.ok && data.ok) {
        setSuccessMsg(`Usuário ${data.user.login} criado! Senha temporária: ${data.generated_password}`);
        setIsCreating(false);
        setLogin("");
        setEmail("");
        setRole("user");
        if (groups.length > 0) setGroupId(groups[0].id);
        fetchUsersAndGroups();
      } else {
        setError(data.error || "Erro ao criar usuário.");
      }
    } catch {
      setError("Erro de conexão.");
    }
  };

  const openEditModal = (target: User) => {
    setEditTarget(target);
    setEditLogin(target.login);
    setEditEmail(target.email || "");
    setEditRole(target.role);
    setEditGroupId(target.group_id ?? (groups.length > 0 ? groups[0].id : ""));
    setActionError("");
  };

  const handleConfirmEdit = async (e: FormEvent) => {
    e.preventDefault();
    if (!editTarget) return;

    if (requiresGroup(editRole) && !editGroupId) {
      setActionError("Selecione um grupo obrigatório para o usuário padrão.");
      return;
    }

    setIsEditing(true);
    setActionError("");

    try {
      const res = await apiPostJson("/api/users/edit.php", {
        user_id: editTarget.id,
        login: editLogin,
        email: editEmail,
        role: editRole,
        group_id: editGroupId ? Number(editGroupId) : null,
      });
      const data = await res.json();

      if (res.ok && data.ok) {
        setSuccessMsg(`Dados do usuário "${data.user.login}" atualizados com sucesso!`);
        setEditTarget(null);
        fetchUsersAndGroups();
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
    if (!genTarget) return;
    setIsGenerating(true);
    setActionError("");

    try {
      const res = await apiPostJson("/api/users/generate_password.php", { user_id: genTarget.id });
      const data = await res.json();

      if (res.ok && data.ok) {
        setGeneratedPasswordResult({
          login: genTarget.login,
          password: data.generated_password,
        });
        setCopied(false);
        fetchUsersAndGroups();
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
    if (generatedPasswordResult?.password) {
      navigator.clipboard.writeText(generatedPasswordResult.password);
      setCopied(true);
      setTimeout(() => setCopied(false), 3000);
    }
  };

  const handleConfirmDelete = async () => {
    if (!deleteTarget) return;
    setIsDeleting(true);
    setActionError("");

    try {
      const res = await apiPostJson("/api/users/delete.php", { user_id: deleteTarget.id });
      const data = await res.json();

      if (res.ok && data.ok) {
        setSuccessMsg(`Usuário "${deleteTarget.login}" foi excluído com sucesso.`);
        setDeleteTarget(null);
        fetchUsersAndGroups();
      } else {
        setActionError(data.error || "Erro ao excluir usuário.");
      }
    } catch {
      setActionError("Erro de conexão ao excluir usuário.");
    } finally {
      setIsDeleting(false);
    }
  };

  if (!canManage) {
    return <DashboardAccessDenied area="a gestão de usuários" />;
  }

  if (loading) return <div className="p-8 text-white">Carregando usuários...</div>;
  if (error && !isCreating) return <div className="p-8 text-red-400">{error}</div>;

  return (
    <div className="p-4 sm:p-8 max-w-6xl mx-auto h-full overflow-y-auto">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
        <div>
          <h1 className="text-2xl font-bold text-white">Gerenciar Usuários</h1>
          <p className="text-sm text-[var(--color-text-on-dark)] mt-1">
            Controle de contas, atribuição de grupos, geração de senhas e auditoria.
          </p>
        </div>
        <div className="flex items-center gap-3">
          <Link
            href="/dashboard/configuracoes/grupos"
            className="px-4 py-2.5 rounded-full text-xs font-semibold bg-white/10 hover:bg-white/20 text-white transition-colors border border-white/15"
          >
            📊 Ver Grupos
          </Link>
          <button 
            onClick={() => { 
              setIsCreating(!isCreating); 
              setError(""); 
              setSuccessMsg(""); 
              if (groups.length > 0 && !groupId) setGroupId(groups[0].id);
            }}
            className="bg-[var(--color-accent)] text-black px-5 py-2.5 rounded-full text-sm font-semibold hover:opacity-90 transition-opacity flex items-center gap-2 shadow-md cursor-pointer"
          >
            {isCreating ? "✕ Cancelar" : "+ Novo Usuário"}
          </button>
        </div>
      </div>

      {successMsg && (
        <div className="mb-6 p-4 bg-emerald-950/60 border border-emerald-500/50 rounded-2xl text-emerald-200 flex items-start justify-between">
          <div>
            <p className="font-semibold text-sm">{successMsg}</p>
            <p className="text-xs mt-1 text-emerald-300/80">Operação concluída com registro na trilha de auditoria.</p>
          </div>
          <button onClick={() => setSuccessMsg("")} className="text-emerald-400 hover:text-white text-sm font-bold ml-4 cursor-pointer">✕</button>
        </div>
      )}

      {error && isCreating && (
        <div className="mb-6 p-4 bg-red-950/60 border border-red-500/50 rounded-2xl text-red-200">
          {error}
        </div>
      )}

      {/* Formulário de Criação */}
      {isCreating && (
        <form onSubmit={handleCreate} className="mb-8 p-6 bg-[rgba(255,255,255,0.04)] border border-[var(--color-border-dark)] rounded-2xl shadow-xl animate-in fade-in duration-200">
          <h2 className="text-lg font-semibold text-white mb-4">Novo Usuário</h2>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <label className="block text-sm text-[var(--color-text-on-dark)]">Login
              <input 
                value={login} 
                onChange={e => setLogin(e.target.value)} 
                placeholder="Ex: joao.silva"
                required 
                className="mt-1 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/30 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]" 
              />
            </label>
            <label className="block text-sm text-[var(--color-text-on-dark)]">E-mail
              <input 
                type="email" 
                value={email} 
                onChange={e => setEmail(e.target.value)} 
                placeholder="Ex: joao@empresa.com"
                required 
                className="mt-1 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/30 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]" 
              />
            </label>
            <label className="block text-sm text-[var(--color-text-on-dark)]">Nível de Acesso
              <select 
                value={role} 
                onChange={e => setRole(e.target.value)} 
                className="mt-1 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/30 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
              >
                {creatableRoles.map((option) => (
                  <option key={option} value={option} className="bg-[#0D1F0F] text-white">
                    {ROLE_LABELS[option]}
                  </option>
                ))}
              </select>
            </label>
            <label className="block text-sm text-[var(--color-text-on-dark)]">Grupo (Power BI)
              <select 
                value={groupId} 
                onChange={e => setGroupId(e.target.value ? Number(e.target.value) : "")}
                required={requiresGroup(role)}
                className="mt-1 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/30 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
              >
                {!requiresGroup(role) && <option value="" className="bg-[#0D1F0F] text-white">Nenhum (Todos/Admin)</option>}
                {groups.map((g) => (
                  <option key={g.id} value={g.id} className="bg-[#0D1F0F] text-white">
                    {g.name}
                  </option>
                ))}
              </select>
            </label>
          </div>
          <div className="mt-5 flex gap-3">
            <button type="submit" className="bg-[var(--color-accent)] text-black px-6 py-2.5 rounded-full text-sm font-semibold hover:opacity-90 transition-opacity cursor-pointer">
              Salvar e Gerar Senha
            </button>
            <button type="button" onClick={() => setIsCreating(false)} className="px-5 py-2.5 rounded-full text-sm font-medium text-white/70 hover:text-white hover:bg-white/5 transition-colors cursor-pointer">
              Cancelar
            </button>
          </div>
        </form>
      )}

      {/* Tabela de Usuários */}
      <div className="bg-[rgba(255,255,255,0.03)] border border-[var(--color-border-dark)] rounded-2xl overflow-hidden shadow-2xl">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm text-white/80">
            <thead className="bg-black/40 text-white border-b border-[var(--color-border-dark)] text-xs uppercase tracking-wider">
              <tr>
                <th className="px-6 py-4 font-semibold">Login</th>
                <th className="px-6 py-4 font-semibold">E-mail</th>
                <th className="px-6 py-4 font-semibold">Nível</th>
                <th className="px-6 py-4 font-semibold">Grupo</th>
                <th className="px-6 py-4 font-semibold">Status</th>
                <th className="px-6 py-4 font-semibold text-right">Ações</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[var(--color-border-dark)]">
              {users.map(u => {
                const canEdit = canEditUser(currentUser, u);
                const canGen = canGeneratePassword(currentUser, u);
                const canDel = canDeleteUser(currentUser, u);

                return (
                  <tr key={u.id} className="hover:bg-white/5 transition-colors">
                    <td className="px-6 py-4 font-medium text-white">
                      <div className="flex items-center gap-2">
                        <span>{u.login}</span>
                        {u.id === currentUser?.id && (
                          <span className="text-[10px] bg-white/10 text-white/70 px-2 py-0.5 rounded-full">você</span>
                        )}
                      </div>
                    </td>
                    <td className="px-6 py-4 text-white/70">{u.email || '-'}</td>
                    <td className="px-6 py-4">
                      <span className={`px-2.5 py-1 rounded-full text-xs font-semibold uppercase tracking-wider ${
                        u.role === 'root' 
                          ? 'bg-red-500/20 text-red-300 border border-red-500/30' 
                          : u.role === 'master' 
                          ? 'bg-blue-500/20 text-blue-300 border border-blue-500/30' 
                          : 'bg-white/10 text-white/90 border border-white/15'
                      }`}>
                        {u.role}
                      </span>
                    </td>
                    <td className="px-6 py-4">
                      {u.group_name ? (
                        <span className="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1 rounded-full bg-[var(--color-accent-soft)] text-[var(--color-accent)] border border-[var(--color-accent)]/20">
                          <span className="h-1.5 w-1.5 rounded-full bg-[var(--color-accent)]" />
                          {u.group_name}
                        </span>
                      ) : (
                        <span className="text-white/40 text-xs">—</span>
                      )}
                    </td>
                    <td className="px-6 py-4">
                      {u.force_password_change ? (
                        <span className="inline-flex items-center gap-1.5 text-amber-400 text-xs font-medium">
                          <span className="h-1.5 w-1.5 rounded-full bg-amber-400" />
                          Troca pendente
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1.5 text-emerald-400 text-xs font-medium">
                          <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" />
                          Ativo
                        </span>
                      )}
                    </td>
                    <td className="px-6 py-4 text-right">
                      <div className="flex items-center justify-end gap-1.5 flex-wrap">
                        {canEdit && (
                          <button
                            onClick={() => openEditModal(u)}
                            title="Editar dados e grupo deste usuário"
                            className="text-xs font-medium px-3 py-1.5 rounded-full border border-blue-500/40 text-blue-300 hover:bg-blue-500/20 hover:text-white transition-colors cursor-pointer"
                          >
                            ✏️ Editar
                          </button>
                        )}

                        {canGen && (
                          <button
                            onClick={() => {
                              setGenTarget(u);
                              setActionError("");
                              setGeneratedPasswordResult(null);
                            }}
                            title="Gerar nova senha temporária para este usuário"
                            className="text-xs font-medium px-3 py-1.5 rounded-full border border-amber-500/40 text-amber-300 hover:bg-amber-500/20 hover:text-white transition-colors cursor-pointer"
                          >
                            🔑 Senha
                          </button>
                        )}

                        {canDel && (
                          <button
                            onClick={() => {
                              setDeleteTarget(u);
                              setActionError("");
                            }}
                            title="Excluir usuário permanentemente"
                            className="text-xs font-medium px-3 py-1.5 rounded-full border border-red-500/40 text-red-300 hover:bg-red-500/20 hover:text-white transition-colors cursor-pointer"
                          >
                            🗑️ Excluir
                          </button>
                        )}

                        <Link 
                          href={`/dashboard/configuracoes/usuarios/ver?id=${u.id}`} 
                          className="text-xs font-semibold text-[var(--color-accent)] hover:underline px-2 py-1.5"
                        >
                          Histórico &rarr;
                        </Link>
                      </div>
                    </td>
                  </tr>
                );
              })}
              {users.length === 0 && (
                <tr><td colSpan={6} className="px-6 py-8 text-center text-white/60">Nenhum usuário cadastrado.</td></tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Modal: Editar Usuário */}
      {editTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm animate-in fade-in duration-150">
          <form onSubmit={handleConfirmEdit} className="w-full max-w-lg bg-[#0D1F0F] border border-[var(--color-border-dark)] rounded-3xl p-6 shadow-2xl">
            <div className="flex items-center gap-3 mb-4 text-blue-400">
              <span className="text-2xl">✏️</span>
              <h3 className="text-xl font-bold text-white">Editar Usuário</h3>
            </div>
            <p className="text-sm text-[var(--color-text-on-dark)] mb-6">
              Atualize as informações e o grupo associado ao usuário <strong className="text-white">{editTarget.login}</strong>.
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
                {editableRoles.length > 1 && (
                  <div>
                    <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)] mb-1">
                      Nível de Acesso
                    </label>
                    <select
                      value={editRole}
                      onChange={(e) => setEditRole(e.target.value)}
                      className="w-full rounded-xl border border-[var(--color-border-dark)] bg-black/40 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
                    >
                      {editableRoles.map((option) => (
                        <option key={option} value={option} className="bg-[#0D1F0F] text-white">
                          {ROLE_LABELS[option]}
                        </option>
                      ))}
                    </select>
                  </div>
                )}

                <div className={editableRoles.length > 1 ? "" : "sm:col-span-2"}>
                  <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)] mb-1">
                    Grupo (Power BI)
                  </label>
                  <select
                    value={editGroupId}
                    onChange={(e) => setEditGroupId(e.target.value ? Number(e.target.value) : "")}
                    required={requiresGroup(editRole)}
                    className="w-full rounded-xl border border-[var(--color-border-dark)] bg-black/40 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
                  >
                    {!requiresGroup(editRole) && <option value="" className="bg-[#0D1F0F] text-white">Nenhum (Todos/Admin)</option>}
                    {groups.map((g) => (
                      <option key={g.id} value={g.id} className="bg-[#0D1F0F] text-white">
                        {g.name}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              {canGeneratePassword(currentUser, editTarget) && (
                <div className="pt-3 border-t border-white/10 flex items-center justify-between gap-3">
                  <div>
                    <p className="text-xs font-semibold text-white">Redefinição de Acesso</p>
                    <p className="text-[11px] text-white/50">Precisa enviar uma nova credencial para este usuário?</p>
                  </div>
                  <button
                    type="button"
                    onClick={() => {
                      const u = editTarget;
                      setEditTarget(null);
                      setGenTarget(u);
                      setActionError("");
                      setGeneratedPasswordResult(null);
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
                onClick={() => { setEditTarget(null); setActionError(""); }}
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
      {genTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
          <div className="w-full max-w-md bg-[#0D1F0F] border border-[var(--color-border-dark)] rounded-3xl p-6 shadow-2xl animate-in fade-in zoom-in-95 duration-200">
            {!generatedPasswordResult ? (
              <>
                <div className="flex items-center gap-3 mb-4 text-amber-400">
                  <span className="text-2xl">🔑</span>
                  <h3 className="text-xl font-bold text-white">Gerar Nova Senha</h3>
                </div>
                <p className="text-sm text-[var(--color-text-on-dark)] mb-4">
                  Deseja gerar uma nova senha temporária para o usuário <strong className="text-white">{genTarget.login}</strong>?
                </p>
                <p className="text-xs text-white/60 mb-6 bg-white/5 p-3 rounded-xl border border-white/10">
                  ⚠️ A senha atual será imediatamente invalidada e o usuário será obrigado a cadastrar uma nova senha no próximo login.
                </p>

                {actionError && (
                  <p className="text-xs text-red-300 mb-4 p-3 bg-red-950/60 border border-red-500/40 rounded-xl">{actionError}</p>
                )}

                <div className="flex justify-end gap-3">
                  <button
                    onClick={() => { setGenTarget(null); setActionError(""); }}
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
                  A nova senha temporária para <strong className="text-white">{generatedPasswordResult.login}</strong> foi criada com sucesso:
                </p>

                <div className="mb-4 p-4 rounded-2xl bg-black/40 border border-[var(--color-accent)]/40 flex items-center justify-between">
                  <code className="text-lg font-mono font-bold text-[var(--color-accent)] select-all tracking-wider">
                    {generatedPasswordResult.password}
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
                  Copie e envie esta senha para o usuário. Esta ação foi registrada no histórico de auditoria.
                </p>

                <div className="flex justify-end">
                  <button
                    onClick={() => {
                      setGenTarget(null);
                      setGeneratedPasswordResult(null);
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
      {deleteTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
          <div className="w-full max-w-md bg-[#0D1F0F] border border-red-500/30 rounded-3xl p-6 shadow-2xl animate-in fade-in zoom-in-95 duration-200">
            <div className="flex items-center gap-3 mb-4 text-red-400">
              <span className="text-2xl">🗑️</span>
              <h3 className="text-xl font-bold text-white">Excluir Usuário</h3>
            </div>
            <p className="text-sm text-[var(--color-text-on-dark)] mb-4">
              Tem certeza que deseja excluir permanentemente o usuário <strong className="text-white">{deleteTarget.login}</strong>?
            </p>
            <p className="text-xs text-red-300/80 mb-6 bg-red-950/40 p-3 rounded-xl border border-red-500/20">
              ⚠️ Esta ação não pode ser desfeita. O usuário perderá o acesso imediatamente.
            </p>

            {actionError && (
              <p className="text-xs text-red-300 mb-4 p-3 bg-red-950/60 border border-red-500/40 rounded-xl">{actionError}</p>
            )}

            <div className="flex justify-end gap-3">
              <button
                onClick={() => { setDeleteTarget(null); setActionError(""); }}
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

