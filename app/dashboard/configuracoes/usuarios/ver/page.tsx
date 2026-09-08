"use client";

import { useEffect, useState, useCallback, Suspense, FormEvent } from "react";
import { useDashboardAuth } from "@/components/DashboardGate";
import { DashboardAccessDenied } from "@/components/DashboardAccessDenied";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { DashboardModal, ModalActions } from "@/components/DashboardModal";
import {
  ArrowRightIcon,
  CheckIcon,
  CopyIcon,
  KeyIcon,
  PencilIcon,
  TrashIcon,
} from "@/components/icons";
import { apiPostJson } from "@/lib/dashboard-api";
import {
  ROLE_LABELS,
  assignableRolesOnEdit,
  canDeleteUser,
  canEditUser,
  canGeneratePassword,
  canManageUsers,
  normalizeRole,
  requiresGroup,
} from "@/lib/authz";
import { formatOsDateTime } from "@/lib/os-share";

const inputClass =
  "mt-1.5 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/30 px-3.5 py-2.5 text-sm text-white outline-none transition-colors placeholder:text-white/30 focus:border-[var(--color-accent)]";

const labelClass = "block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)]";

const ghostButton =
  "inline-flex items-center justify-center gap-2 rounded-full px-5 py-2.5 text-sm font-medium text-white/70 transition-colors hover:bg-white/5 hover:text-white disabled:opacity-50";

const alertError = "mt-4 rounded-xl border border-red-500/40 bg-red-950/60 p-3 text-sm text-red-200";

/** Rótulo e cor de cada evento do log. Ação desconhecida sai como veio, em cinza. */
const ACTION_BADGES: Record<string, { label: string; tone: string; dot: string }> = {
  login: { label: "Login", tone: "border-emerald-500/30 bg-emerald-500/15 text-emerald-300", dot: "bg-emerald-400" },
  logout: { label: "Logout", tone: "border-white/15 bg-white/10 text-white/80", dot: "bg-white/50" },
  change_password: { label: "Troca de Senha", tone: "border-sky-500/30 bg-sky-500/15 text-sky-300", dot: "bg-sky-400" },
  reset_password: { label: "Senha Redefinida", tone: "border-amber-500/30 bg-amber-500/15 text-amber-300", dot: "bg-amber-400" },
  create_user: { label: "Conta Criada", tone: "border-purple-500/30 bg-purple-500/15 text-purple-300", dot: "bg-purple-400" },
  edit_user: { label: "Edição de Cadastro", tone: "border-sky-500/30 bg-sky-500/15 text-sky-300", dot: "bg-sky-400" },
  delete_user: { label: "Exclusão", tone: "border-red-500/30 bg-red-500/15 text-red-300", dot: "bg-red-400" },
};

function ActionBadge({ action }: { action: string }) {
  const badge = ACTION_BADGES[action] ?? { label: action, tone: "border-white/15 bg-white/10 text-white/80", dot: "bg-white/50" };

  return (
    <span className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2.5 py-1 text-xs font-semibold ${badge.tone}`}>
      <span className={`h-1.5 w-1.5 rounded-full ${badge.dot}`} />
      {badge.label}
    </span>
  );
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
    <Suspense fallback={<div className="p-8 text-white">Carregando...</div>}>
      <UsuarioDetails />
    </Suspense>
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

  const canManage = canManageUsers(currentUser);

  const fetchUserData = useCallback(() => {
    // Mesma regra da listagem: sem permissão, nem chega a pedir os dados.
    if (!canManage) {
      return;
    }
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
  }, [id, canManage]);

  useEffect(() => {
    fetchUserData();
  }, [fetchUserData]);

  const mayEditUser = user ? canEditUser(currentUser, user) : false;
  const mayGeneratePassword = user ? canGeneratePassword(currentUser, user) : false;
  const mayDeleteUser = user ? canDeleteUser(currentUser, user) : false;
  const editableRoles = user ? assignableRolesOnEdit(currentUser, user) : [];

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

    if (requiresGroup(editRole) && !editGroupId) {
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
        router.push("/dashboard/configuracoes/usuarios");
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
        Carregando histórico...
      </div>
    );
  }
  if (error) {
    return (
      <div className="p-6 sm:p-8">
        <div className="rounded-2xl border border-red-500/40 bg-red-950/60 p-4 text-sm text-red-200">{error}</div>
      </div>
    );
  }
  if (!user) return <div className="p-8 text-white">Usuário não encontrado.</div>;

  return (
    <div className="mx-auto max-w-6xl p-4 sm:p-6 lg:p-8">
      <Link
        href="/dashboard/configuracoes/usuarios"
        className="mb-5 inline-flex items-center gap-2 text-sm font-medium text-[var(--color-accent)] hover:underline"
      >
        <ArrowRightIcon width={16} height={16} className="rotate-180" />
        Voltar para Usuários
      </Link>

      {/* Card de detalhes do usuário */}
      <div className="mb-6 flex flex-col gap-5 rounded-3xl border border-[var(--color-border-dark)] bg-[rgba(255,255,255,0.03)] p-5 shadow-2xl sm:p-6 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex min-w-0 items-start gap-4">
          <span
            aria-hidden="true"
            className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-[var(--color-accent)] text-xl font-bold uppercase text-[var(--color-bg-dark)]"
          >
            {user.login.slice(0, 1)}
          </span>
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="break-all text-2xl font-bold text-white sm:text-3xl">{user.login}</h1>
              <RoleBadge role={user.role} />
              {user.group_name && (
                <span className="inline-flex items-center gap-1.5 rounded-full border border-[var(--color-accent)]/20 bg-[var(--color-accent-soft)] px-2.5 py-1 text-xs font-semibold text-[var(--color-accent)]">
                  <span className="h-1.5 w-1.5 rounded-full bg-[var(--color-accent)]" />
                  {user.group_name}
                </span>
              )}
              {user.force_password_change && (
                <span className="inline-flex items-center gap-1.5 rounded-full border border-amber-500/30 bg-amber-500/15 px-2.5 py-1 text-xs font-semibold text-amber-300">
                  <span className="h-1.5 w-1.5 rounded-full bg-amber-400" />
                  Troca de senha pendente
                </span>
              )}
            </div>
            <p className="mt-2 break-all text-sm text-[var(--color-text-on-dark)]">
              {user.email || <span className="text-white/45">Sem e-mail</span>}
            </p>
            <p className="mt-1 text-xs text-white/50">
              Cadastrado em {formatOsDateTime(user.created_at)}
            </p>
          </div>
        </div>

        {/* Ações de administrador */}
        <div className="flex flex-wrap items-center gap-2">
          {mayEditUser && (
            <button
              type="button"
              onClick={openEditModal}
              className="inline-flex items-center gap-2 rounded-full border border-sky-500/40 bg-sky-500/15 px-4 py-2 text-xs font-semibold text-sky-300 transition-colors hover:bg-sky-500/25 sm:text-sm"
            >
              <PencilIcon width={15} height={15} />
              Editar
            </button>
          )}

          {mayGeneratePassword && (
            <button
              type="button"
              onClick={() => {
                setShowGenModal(true);
                setActionError("");
                setGenPasswordResult(null);
              }}
              className="inline-flex items-center gap-2 rounded-full border border-amber-500/40 bg-amber-500/15 px-4 py-2 text-xs font-semibold text-amber-300 transition-colors hover:bg-amber-500/25 sm:text-sm"
            >
              <KeyIcon width={15} height={15} />
              Nova Senha
            </button>
          )}

          {mayDeleteUser && (
            <button
              type="button"
              onClick={() => {
                setShowDeleteModal(true);
                setActionError("");
              }}
              className="inline-flex items-center gap-2 rounded-full border border-red-500/40 bg-red-500/15 px-4 py-2 text-xs font-semibold text-red-300 transition-colors hover:bg-red-500/25 sm:text-sm"
            >
              <TrashIcon width={15} height={15} />
              Excluir
            </button>
          )}
        </div>
      </div>

      {/* Histórico de auditoria */}
      <section className="overflow-hidden rounded-3xl border border-[var(--color-border-dark)] bg-[rgba(255,255,255,0.03)] shadow-2xl">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--color-border-dark)] bg-black/30 px-4 py-4 sm:px-6">
          <h2 className="text-lg font-semibold text-white">Histórico de Atividades</h2>
          <span className="rounded-full border border-[var(--color-accent)]/20 bg-[var(--color-accent-soft)] px-3 py-1 text-xs text-[var(--color-accent)]">
            {logs.length} {logs.length === 1 ? "evento" : "eventos"}
          </span>
        </div>

        <div className="data-table-wrap">

          <table className="data-table text-white/85">
          <thead className="bg-black/40 text-white">
            <tr>
              <th>Data / Hora</th>
              <th>Evento</th>
              <th>Detalhes</th>
              <th>IP</th>
              <th>Dispositivo</th>
            </tr>
          </thead>
          <tbody>
            {logs.map((log) => (
              <tr key={log.id} className="transition-colors xl:hover:bg-white/5">
                <td data-label="Data" className="whitespace-nowrap text-xs font-medium text-white">
                  {formatOsDateTime(log.created_at)}
                </td>
                <td data-label="Evento">
                  <ActionBadge action={log.action} />
                </td>
                <td data-label="Detalhes" className="text-xs">
                  <div className="min-w-0">
                    <p className="break-words font-medium text-white/90">{log.description || "—"}</p>
                    {log.performed_by_login && log.performed_by_login !== user.login && (
                      <p className="mt-0.5 text-[11px] text-amber-300/80">
                        por <span className="font-mono">{log.performed_by_login}</span>
                      </p>
                    )}
                  </div>
                </td>
                <td data-label="IP" className="whitespace-nowrap font-mono text-xs text-white/70">
                  {log.ip_address}
                </td>
                <td data-label="Dispositivo" className="text-xs text-white/60">
                  <span className="block max-w-[14rem] truncate xl:max-w-[12rem]" title={log.user_agent}>
                    {log.user_agent}
                  </span>
                </td>
              </tr>
            ))}
            {logs.length === 0 && (
              <tr>
                <td colSpan={5} className="data-table-empty py-10 text-center text-sm text-white/60">
                  Nenhum registro de atividade para este usuário.
                </td>
              </tr>
            )}
          </tbody>
          </table>
        </div>
      </section>

      {/* Modal: Editar Usuário */}
      {showEditModal && (
        <DashboardModal
          title="Editar Usuário"
          icon={<PencilIcon width={18} height={18} />}
          tone="info"
          as="form"
          onSubmit={handleConfirmEdit}
          onClose={() => { setShowEditModal(false); setActionError(""); }}
        >
          <p className="mb-5 text-sm text-[var(--color-text-on-dark)]">
            Conta <strong className="text-white">{user.login}</strong>
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

            {mayGeneratePassword && (
              <div className="flex items-center justify-between gap-3 border-t border-white/10 pt-4">
                <p className="text-xs font-semibold text-white">Acesso</p>
                <button
                  type="button"
                  onClick={() => {
                    setShowEditModal(false);
                    setShowGenModal(true);
                    setActionError("");
                    setGenPasswordResult(null);
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
              onClick={() => { setShowEditModal(false); setActionError(""); }}
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
      {showGenModal && (
        <DashboardModal
          title={genPasswordResult ? "Nova Senha Gerada" : "Gerar Nova Senha"}
          icon={genPasswordResult ? <CheckIcon width={18} height={18} /> : <KeyIcon width={18} height={18} />}
          tone={genPasswordResult ? "success" : "warning"}
          size="sm"
          onClose={() => { setShowGenModal(false); setGenPasswordResult(null); setActionError(""); }}
        >
          {!genPasswordResult ? (
            <>
              <p className="text-sm text-[var(--color-text-on-dark)]">
                Gerar uma nova senha temporária para <strong className="text-white">{user.login}</strong>?
              </p>
              <p className="mt-3 rounded-xl border border-amber-500/20 bg-amber-500/10 p-3 text-xs text-amber-200/90">
                A senha atual deixa de valer na hora e o usuário define outra no próximo login.
              </p>

              {actionError && <p role="alert" className={alertError}>{actionError}</p>}

              <ModalActions>
                <button
                  type="button"
                  onClick={() => { setShowGenModal(false); setActionError(""); }}
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
                Senha temporária de <strong className="text-white">{user.login}</strong>:
              </p>

              <div className="mt-4 flex items-center justify-between gap-3 rounded-2xl border border-[var(--color-accent)]/40 bg-black/40 p-4">
                <code className="min-w-0 select-all break-all font-mono text-lg font-bold tracking-wider text-[var(--color-accent)]">
                  {genPasswordResult}
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
                    setShowGenModal(false);
                    setGenPasswordResult(null);
                  }}
                  className="inline-flex items-center justify-center rounded-full bg-[var(--color-accent)] px-6 py-2.5 text-sm font-semibold text-[var(--color-bg-dark)] transition-opacity hover:opacity-90"
                >
                  Concluir
                </button>
              </ModalActions>
            </>
          )}
        </DashboardModal>
      )}

      {/* Modal: Excluir Usuário */}
      {showDeleteModal && (
        <DashboardModal
          title="Excluir Usuário"
          icon={<TrashIcon width={18} height={18} />}
          tone="danger"
          size="sm"
          onClose={() => { setShowDeleteModal(false); setActionError(""); }}
        >
          <p className="text-sm text-[var(--color-text-on-dark)]">
            Excluir permanentemente <strong className="text-white">{user.login}</strong>?
          </p>
          <p className="mt-3 rounded-xl border border-red-500/20 bg-red-950/40 p-3 text-xs text-red-300/90">
            Não dá para desfazer. O acesso é cortado na hora.
          </p>

          {actionError && <p role="alert" className={alertError}>{actionError}</p>}

          <ModalActions>
            <button
              type="button"
              onClick={() => { setShowDeleteModal(false); setActionError(""); }}
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
