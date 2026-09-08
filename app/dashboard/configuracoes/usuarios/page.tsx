"use client";

import { useEffect, useState, useCallback, useMemo, FormEvent } from "react";
import { useDashboardAuth } from "@/components/DashboardGate";
import { DashboardAccessDenied } from "@/components/DashboardAccessDenied";
import { DashboardModal, ModalActions } from "@/components/DashboardModal";
import {
  CheckIcon,
  ClockIcon,
  CloseIcon,
  CopyIcon,
  KeyIcon,
  PencilIcon,
  PlusIcon,
  SearchIcon,
  TrashIcon,
  UsersIcon,
} from "@/components/icons";
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
  normalizeRole,
  requiresGroup,
} from "@/lib/authz";
import { formatOsDateTime } from "@/lib/os-share";

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
  last_login: string | null;
};

const inputClass =
  "mt-1.5 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/30 px-3.5 py-2.5 text-sm text-white outline-none transition-colors placeholder:text-white/30 focus:border-[var(--color-accent)]";

const labelClass = "block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)]";

const primaryButton =
  "inline-flex items-center justify-center gap-2 rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-sm font-semibold text-[var(--color-bg-dark)] transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50";

const ghostButton =
  "inline-flex items-center justify-center gap-2 rounded-full px-5 py-2.5 text-sm font-medium text-white/70 transition-colors hover:bg-white/5 hover:text-white disabled:opacity-50";

const alertError = "mt-4 rounded-xl border border-red-500/40 bg-red-950/60 p-3 text-sm text-red-200";

export default function UsuariosPage() {
  return <UsuariosList />;
}

function RoleBadge({ role }: { role: string }) {
  const known = normalizeRole(role);
  const tone =
    known === "root"
      ? "border-red-500/30 bg-red-500/15 text-red-300"
      : known === "master"
        ? "border-sky-500/30 bg-sky-500/15 text-sky-300"
        : "border-white/15 bg-white/10 text-white/85";

  return (
    <span className={`inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wider ${tone}`}>
      {known ? ROLE_LABELS[known] : role}
    </span>
  );
}

/** Círculo com a inicial do login, para a linha ter um ponto de ancoragem visual. */
function Avatar({ login, self }: { login: string; self: boolean }) {
  return (
    <span
      aria-hidden="true"
      className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-bold uppercase ${
        self ? "bg-[var(--color-accent)] text-[var(--color-bg-dark)]" : "bg-white/10 text-white"
      }`}
    >
      {login.slice(0, 1)}
    </span>
  );
}

function IconButton({
  label,
  tone,
  onClick,
  children,
}: {
  label: string;
  tone: "info" | "warning" | "danger";
  onClick: () => void;
  children: React.ReactNode;
}) {
  const toneClass = {
    info: "border-sky-500/30 text-sky-300 hover:bg-sky-500/15",
    warning: "border-amber-500/30 text-amber-300 hover:bg-amber-500/15",
    danger: "border-red-500/30 text-red-300 hover:bg-red-500/15",
  }[tone];

  return (
    <button
      type="button"
      onClick={onClick}
      title={label}
      aria-label={label}
      className={`flex h-9 w-9 items-center justify-center rounded-full border transition-colors hover:text-white ${toneClass}`}
    >
      {children}
    </button>
  );
}

function UsuariosList() {
  const { user: currentUser } = useDashboardAuth();
  const [users, setUsers] = useState<User[]>([]);
  const [groups, setGroups] = useState<Group[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [isCreating, setIsCreating] = useState(false);
  const [query, setQuery] = useState("");

  // Form states: Criar Usuário
  const [login, setLogin] = useState("");
  const [email, setEmail] = useState("");
  const [role, setRole] = useState("user");
  const [groupId, setGroupId] = useState<number | "">("");
  const [successMsg, setSuccessMsg] = useState("");
  const [isSubmittingCreate, setIsSubmittingCreate] = useState(false);

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

  const filteredUsers = useMemo(() => {
    const term = query.trim().toLowerCase();
    if (!term) return users;

    return users.filter((u) =>
      [u.login, u.email ?? "", u.group_name ?? ""].some((field) => field.toLowerCase().includes(term))
    );
  }, [users, query]);

  const handleCreate = async (e: FormEvent) => {
    e.preventDefault();
    setError("");
    setSuccessMsg("");

    if (requiresGroup(role) && !groupId) {
      setError("Selecione um grupo obrigatório para o usuário padrão.");
      return;
    }

    setIsSubmittingCreate(true);
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
    } finally {
      setIsSubmittingCreate(false);
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

  if (loading) {
    return (
      <div className="flex items-center gap-3 p-6 text-sm text-[var(--color-text-on-dark)] sm:p-8">
        <span className="h-5 w-5 animate-spin rounded-full border-2 border-[var(--color-accent-soft)] border-t-[var(--color-accent)]" />
        Carregando usuários...
      </div>
    );
  }

  if (error && !isCreating) {
    return (
      <div className="p-6 sm:p-8">
        <div className="rounded-2xl border border-red-500/40 bg-red-950/60 p-4 text-sm text-red-200">{error}</div>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-6xl p-4 sm:p-6 lg:p-8">
      {/* Cabeçalho */}
      <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="min-w-0">
          <h1 className="text-2xl font-bold text-white">Gerenciar Usuários</h1>
          <p className="mt-1 text-sm text-[var(--color-text-on-dark)]">
            Contas, grupos do Power BI e senhas de acesso.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2 sm:justify-end">
          <Link
            href="/dashboard/configuracoes/grupos"
            className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-4 py-2.5 text-xs font-semibold text-white transition-colors hover:bg-white/20"
          >
            <UsersIcon width={15} height={15} />
            Ver Grupos
          </Link>
          <button
            type="button"
            onClick={() => {
              setIsCreating(!isCreating);
              setError("");
              setSuccessMsg("");
              if (groups.length > 0 && !groupId) setGroupId(groups[0].id);
            }}
            className={isCreating ? ghostButton : primaryButton}
          >
            {isCreating ? <CloseIcon width={16} height={16} /> : <PlusIcon width={16} height={16} />}
            {isCreating ? "Cancelar" : "Novo Usuário"}
          </button>
        </div>
      </div>

      {successMsg && (
        <div role="status" className="mb-6 flex items-start justify-between gap-4 rounded-2xl border border-emerald-500/50 bg-emerald-950/60 p-4 text-emerald-200">
          <p className="min-w-0 break-words text-sm font-semibold">{successMsg}</p>
          <button
            type="button"
            onClick={() => setSuccessMsg("")}
            aria-label="Fechar aviso"
            className="shrink-0 rounded-full p-1 text-emerald-400 transition-colors hover:bg-white/10 hover:text-white"
          >
            <CloseIcon width={16} height={16} />
          </button>
        </div>
      )}

      {error && isCreating && (
        <div role="alert" className="mb-6 rounded-2xl border border-red-500/50 bg-red-950/60 p-4 text-sm text-red-200">
          {error}
        </div>
      )}

      {/* Formulário de Criação */}
      {isCreating && (
        <form onSubmit={handleCreate} className="mb-6 rounded-2xl border border-[var(--color-border-dark)] bg-[rgba(255,255,255,0.04)] p-5 shadow-xl sm:p-6">
          <h2 className="mb-4 text-lg font-semibold text-white">Novo Usuário</h2>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <label className={labelClass}>Login
              <input
                value={login}
                onChange={e => setLogin(e.target.value)}
                placeholder="joao.silva"
                required
                autoComplete="off"
                className={inputClass}
              />
            </label>
            <label className={labelClass}>E-mail <span className="font-normal normal-case tracking-normal text-white/40">(opcional)</span>
              <input
                type="email"
                value={email}
                onChange={e => setEmail(e.target.value)}
                autoComplete="off"
                className={inputClass}
              />
            </label>
            <label className={labelClass}>Nível de Acesso
              <select
                value={role}
                onChange={e => setRole(e.target.value)}
                className={inputClass}
              >
                {creatableRoles.map((option) => (
                  <option key={option} value={option}>
                    {ROLE_LABELS[option]}
                  </option>
                ))}
              </select>
            </label>
            <label className={labelClass}>Grupo (Power BI)
              <select
                value={groupId}
                onChange={e => setGroupId(e.target.value ? Number(e.target.value) : "")}
                required={requiresGroup(role)}
                className={inputClass}
              >
                {!requiresGroup(role) && <option value="">Nenhum (Todos/Admin)</option>}
                {groups.map((g) => (
                  <option key={g.id} value={g.id}>
                    {g.name}
                  </option>
                ))}
              </select>
            </label>
          </div>
          <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:gap-3">
            <button type="button" onClick={() => setIsCreating(false)} className={ghostButton}>
              Cancelar
            </button>
            <button type="submit" disabled={isSubmittingCreate} className={primaryButton}>
              {isSubmittingCreate ? "Salvando..." : "Salvar e Gerar Senha"}
            </button>
          </div>
        </form>
      )}

      {/* Lista */}
      <section className="overflow-hidden rounded-2xl border border-[var(--color-border-dark)] bg-[rgba(255,255,255,0.03)] shadow-2xl">
        <div className="flex flex-col gap-3 border-b border-[var(--color-border-dark)] p-4 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm text-[var(--color-text-on-dark)]">
            <strong className="font-semibold text-white">{users.length}</strong>{" "}
            {users.length === 1 ? "usuário" : "usuários"}
            {query.trim() && filteredUsers.length !== users.length && (
              <span className="text-white/50"> · {filteredUsers.length} na busca</span>
            )}
          </p>
          <label className="relative block w-full sm:w-72">
            <span className="sr-only">Buscar usuário</span>
            <SearchIcon width={16} height={16} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-white/40" />
            <input
              type="search"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Buscar"
              className="w-full rounded-full border border-[var(--color-border-dark)] bg-black/30 py-2 pl-10 pr-4 text-sm text-white outline-none transition-colors placeholder:text-white/30 focus:border-[var(--color-accent)]"
            />
          </label>
        </div>

        <div className="data-table-wrap">

          <table className="data-table text-white/85">
          <thead className="bg-black/40 text-white">
            <tr>
              <th>Usuário</th>
              <th>Nível</th>
              <th>Grupo</th>
              <th>Status</th>
              <th className="text-right">Ações</th>
            </tr>
          </thead>
          <tbody>
            {filteredUsers.map(u => {
              const canEdit = canEditUser(currentUser, u);
              const canGen = canGeneratePassword(currentUser, u);
              const canDel = canDeleteUser(currentUser, u);
              const isSelf = u.id === currentUser?.id;

              return (
                <tr key={u.id} className="transition-colors xl:hover:bg-white/5">
                  <td>
                    <div className="flex min-w-0 items-center gap-3">
                      <Avatar login={u.login} self={isSelf} />
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="truncate font-semibold text-white">{u.login}</span>
                          {isSelf && (
                            <span className="rounded-full bg-[var(--color-accent-soft)] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-[var(--color-accent)]">você</span>
                          )}
                        </div>
                        <p className="truncate text-xs text-white/55">{u.email || "sem e-mail"}</p>
                      </div>
                    </div>
                  </td>
                  <td data-label="Nível">
                    <RoleBadge role={u.role} />
                  </td>
                  <td data-label="Grupo">
                    {u.group_name ? (
                      <span className="inline-flex items-center gap-1.5 rounded-full border border-[var(--color-accent)]/20 bg-[var(--color-accent-soft)] px-2.5 py-1 text-xs font-semibold text-[var(--color-accent)]">
                        <span className="h-1.5 w-1.5 rounded-full bg-[var(--color-accent)]" />
                        {u.group_name}
                      </span>
                    ) : (
                      <span className="text-xs text-white/40">—</span>
                    )}
                  </td>
                  <td data-label="Status">
                    <div className="space-y-0.5">
                      {u.force_password_change ? (
                        <span className="inline-flex items-center gap-1.5 text-xs font-medium text-amber-400">
                          <span className="h-1.5 w-1.5 rounded-full bg-amber-400" />
                          Troca de senha pendente
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-400">
                          <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" />
                          Ativo
                        </span>
                      )}
                      <p className="text-[11px] text-white/45">
                        {u.last_login ? `Último acesso ${formatOsDateTime(u.last_login)}` : "Nunca acessou"}
                      </p>
                    </div>
                  </td>
                  <td className="data-table-actions">
                    <div className="flex items-center justify-end gap-1.5">
                      {canEdit && (
                        <IconButton label={`Editar ${u.login}`} tone="info" onClick={() => openEditModal(u)}>
                          <PencilIcon width={16} height={16} />
                        </IconButton>
                      )}
                      {canGen && (
                        <IconButton
                          label={`Gerar nova senha para ${u.login}`}
                          tone="warning"
                          onClick={() => {
                            setGenTarget(u);
                            setActionError("");
                            setGeneratedPasswordResult(null);
                          }}
                        >
                          <KeyIcon width={16} height={16} />
                        </IconButton>
                      )}
                      {canDel && (
                        <IconButton
                          label={`Excluir ${u.login}`}
                          tone="danger"
                          onClick={() => {
                            setDeleteTarget(u);
                            setActionError("");
                          }}
                        >
                          <TrashIcon width={16} height={16} />
                        </IconButton>
                      )}
                      <Link
                        href={`/dashboard/configuracoes/usuarios/ver?id=${u.id}`}
                        title={`Histórico de ${u.login}`}
                        className="ml-1 inline-flex items-center gap-1.5 rounded-full px-3 py-2 text-xs font-semibold text-[var(--color-accent)] transition-colors hover:bg-[var(--color-accent-soft)]"
                      >
                        <ClockIcon width={15} height={15} />
                        Histórico
                      </Link>
                    </div>
                  </td>
                </tr>
              );
            })}
            {users.length === 0 && (
              <tr>
                <td colSpan={5} className="data-table-empty py-10 text-center text-sm text-white/60">Nenhum usuário cadastrado.</td>
              </tr>
            )}
            {users.length > 0 && filteredUsers.length === 0 && (
              <tr>
                <td colSpan={5} className="data-table-empty py-10 text-center text-sm text-white/60">
                  Nenhum usuário encontrado para “{query.trim()}”.
                </td>
              </tr>
            )}
          </tbody>
          </table>
        </div>
      </section>

      {/* Modal: Editar Usuário */}
      {editTarget && (
        <DashboardModal
          title="Editar Usuário"
          icon={<PencilIcon width={18} height={18} />}
          tone="info"
          as="form"
          onSubmit={handleConfirmEdit}
          onClose={() => { setEditTarget(null); setActionError(""); }}
        >
          <p className="mb-5 text-sm text-[var(--color-text-on-dark)]">
            Conta <strong className="text-white">{editTarget.login}</strong>
          </p>

          <div className="space-y-4">
            <label className={labelClass}>Login
              <input
                value={editLogin}
                onChange={(e) => setEditLogin(e.target.value)}
                required
                className={inputClass}
              />
            </label>

            <label className={labelClass}>E-mail
              <input
                type="email"
                value={editEmail}
                onChange={(e) => setEditEmail(e.target.value)}
                required
                className={inputClass}
              />
            </label>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              {editableRoles.length > 1 && (
                <label className={labelClass}>Nível de Acesso
                  <select
                    value={editRole}
                    onChange={(e) => setEditRole(e.target.value)}
                    className={inputClass}
                  >
                    {editableRoles.map((option) => (
                      <option key={option} value={option}>
                        {ROLE_LABELS[option]}
                      </option>
                    ))}
                  </select>
                </label>
              )}

              <label className={`${labelClass} ${editableRoles.length > 1 ? "" : "sm:col-span-2"}`}>Grupo (Power BI)
                <select
                  value={editGroupId}
                  onChange={(e) => setEditGroupId(e.target.value ? Number(e.target.value) : "")}
                  required={requiresGroup(editRole)}
                  className={inputClass}
                >
                  {!requiresGroup(editRole) && <option value="">Nenhum (Todos/Admin)</option>}
                  {groups.map((g) => (
                    <option key={g.id} value={g.id}>
                      {g.name}
                    </option>
                  ))}
                </select>
              </label>
            </div>

            {canGeneratePassword(currentUser, editTarget) && (
              <div className="flex items-center justify-between gap-3 border-t border-white/10 pt-4">
                <p className="text-xs font-semibold text-white">Acesso</p>
                <button
                  type="button"
                  onClick={() => {
                    const u = editTarget;
                    setEditTarget(null);
                    setGenTarget(u);
                    setActionError("");
                    setGeneratedPasswordResult(null);
                  }}
                  className="inline-flex items-center gap-2 whitespace-nowrap rounded-full border border-amber-500/40 px-3.5 py-1.5 text-xs font-semibold text-amber-300 transition-colors hover:bg-amber-500/20 hover:text-white"
                >
                  <KeyIcon width={14} height={14} />
                  Gerar Nova Senha
                </button>
              </div>
            )}
          </div>

          {actionError && <p role="alert" className={alertError}>{actionError}</p>}

          <ModalActions>
            <button
              type="button"
              onClick={() => { setEditTarget(null); setActionError(""); }}
              disabled={isEditing}
              className={ghostButton}
            >
              Cancelar
            </button>
            <button
              type="submit"
              disabled={isEditing}
              className="inline-flex items-center justify-center rounded-full bg-sky-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg transition-colors hover:bg-sky-500 disabled:opacity-50"
            >
              {isEditing ? "Salvando..." : "Salvar Alterações"}
            </button>
          </ModalActions>
        </DashboardModal>
      )}

      {/* Modal: Gerar Senha */}
      {genTarget && (
        <DashboardModal
          title={generatedPasswordResult ? "Nova Senha Gerada" : "Gerar Nova Senha"}
          icon={generatedPasswordResult ? <CheckIcon width={18} height={18} /> : <KeyIcon width={18} height={18} />}
          tone={generatedPasswordResult ? "success" : "warning"}
          size="sm"
          onClose={() => { setGenTarget(null); setGeneratedPasswordResult(null); setActionError(""); }}
        >
          {!generatedPasswordResult ? (
            <>
              <p className="text-sm text-[var(--color-text-on-dark)]">
                Gerar uma nova senha temporária para <strong className="text-white">{genTarget.login}</strong>?
              </p>
              <p className="mt-3 rounded-xl border border-amber-500/20 bg-amber-500/10 p-3 text-xs text-amber-200/90">
                A senha atual deixa de valer na hora e o usuário define outra no próximo login.
              </p>

              {actionError && <p role="alert" className={alertError}>{actionError}</p>}

              <ModalActions>
                <button
                  type="button"
                  onClick={() => { setGenTarget(null); setActionError(""); }}
                  disabled={isGenerating}
                  className={ghostButton}
                >
                  Cancelar
                </button>
                <button
                  type="button"
                  onClick={handleConfirmGeneratePassword}
                  disabled={isGenerating}
                  className="inline-flex items-center justify-center rounded-full bg-amber-500 px-6 py-2.5 text-sm font-semibold text-black shadow-lg transition-colors hover:bg-amber-400 disabled:opacity-50"
                >
                  {isGenerating ? "Gerando..." : "Confirmar e Gerar"}
                </button>
              </ModalActions>
            </>
          ) : (
            <>
              <p className="text-sm text-[var(--color-text-on-dark)]">
                Senha temporária de <strong className="text-white">{generatedPasswordResult.login}</strong>:
              </p>

              <div className="mt-4 flex items-center justify-between gap-3 rounded-2xl border border-[var(--color-accent)]/40 bg-black/40 p-4">
                <code className="min-w-0 select-all break-all font-mono text-lg font-bold tracking-wider text-[var(--color-accent)]">
                  {generatedPasswordResult.password}
                </code>
                <button
                  type="button"
                  onClick={handleCopyPassword}
                  className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-semibold transition-all ${
                    copied
                      ? "bg-emerald-500 text-black"
                      : "bg-white/10 text-white hover:bg-white/20"
                  }`}
                >
                  {copied ? <CheckIcon width={14} height={14} /> : <CopyIcon width={14} height={14} />}
                  {copied ? "Copiado" : "Copiar"}
                </button>
              </div>

              <ModalActions>
                <button
                  type="button"
                  onClick={() => {
                    setGenTarget(null);
                    setGeneratedPasswordResult(null);
                  }}
                  className={primaryButton}
                >
                  Concluir
                </button>
              </ModalActions>
            </>
          )}
        </DashboardModal>
      )}

      {/* Modal: Excluir Usuário */}
      {deleteTarget && (
        <DashboardModal
          title="Excluir Usuário"
          icon={<TrashIcon width={18} height={18} />}
          tone="danger"
          size="sm"
          onClose={() => { setDeleteTarget(null); setActionError(""); }}
        >
          <p className="text-sm text-[var(--color-text-on-dark)]">
            Excluir permanentemente <strong className="text-white">{deleteTarget.login}</strong>?
          </p>
          <p className="mt-3 rounded-xl border border-red-500/20 bg-red-950/40 p-3 text-xs text-red-300/90">
            Não dá para desfazer. O acesso é cortado na hora.
          </p>

          {actionError && <p role="alert" className={alertError}>{actionError}</p>}

          <ModalActions>
            <button
              type="button"
              onClick={() => { setDeleteTarget(null); setActionError(""); }}
              disabled={isDeleting}
              className={ghostButton}
            >
              Cancelar
            </button>
            <button
              type="button"
              onClick={handleConfirmDelete}
              disabled={isDeleting}
              className="inline-flex items-center justify-center rounded-full bg-red-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg transition-colors hover:bg-red-500 disabled:opacity-50"
            >
              {isDeleting ? "Excluindo..." : "Confirmar Exclusão"}
            </button>
          </ModalActions>
        </DashboardModal>
      )}
    </div>
  );
}
