"use client";

import { useEffect, useRef, useState, FormEvent } from "react";
import { DashboardModal, ModalActions } from "@/components/DashboardModal";
import { LogoCropper } from "@/components/LogoCropper";
import {
  CloseIcon,
  CropIcon,
  ImageIcon,
  PencilIcon,
  PlusIcon,
  TrashIcon,
  UploadIcon,
} from "@/components/icons";
import { apiFetch, apiPostJson } from "@/lib/dashboard-api";

type Company = {
  id: number;
  name: string;
  logo_url: string;
  is_active: number;
};

const inputClass =
  "mt-1.5 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/30 px-3.5 py-2.5 text-sm text-white outline-none transition-colors placeholder:text-white/30 focus:border-[var(--color-accent)]";

const labelClass = "block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)]";

const primaryButton =
  "inline-flex items-center justify-center gap-2 rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-sm font-semibold text-[var(--color-bg-dark)] transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50";

const ghostButton =
  "inline-flex items-center justify-center gap-2 rounded-full px-5 py-2.5 text-sm font-medium text-white/70 transition-colors hover:bg-white/5 hover:text-white disabled:opacity-50";

/** JSON da resposta, ou null quando o servidor devolveu outra coisa (HTML de erro, página em branco). */
async function readJson(res: Response): Promise<Record<string, unknown> | null> {
  try {
    const data: unknown = await res.json();
    return data && typeof data === "object" ? (data as Record<string, unknown>) : null;
  } catch {
    return null;
  }
}

function errorMessage(data: Record<string, unknown> | null, res: Response, fallback: string): string {
  if (typeof data?.error === "string" && data.error) return data.error;
  if (data === null) return `${fallback} O servidor respondeu HTTP ${res.status} sem JSON.`;
  return fallback;
}

/** A logo do jeito que aparece no carrossel da home: caixa clara, 5:2, contida. */
function LogoTile({ src, alt, className = "" }: { src: string; alt: string; className?: string }) {
  return (
    <span className={`flex items-center justify-center rounded-[10px] border border-[var(--color-border-light)] bg-[var(--color-bg-light)] px-3 ${className}`}>
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img src={src} alt={alt} className="max-h-full max-w-full object-contain" draggable={false} />
    </span>
  );
}

export default function EmpresasPage() {
  const [companies, setCompanies] = useState<Company[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [successMsg, setSuccessMsg] = useState("");

  const [isCreating, setIsCreating] = useState(false);
  const [newName, setNewName] = useState("");
  const [newLogoUrl, setNewLogoUrl] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Company | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);

  // Imagem: o arquivo original fica guardado para reabrir o recorte; o que
  // vai para o servidor é o PNG recortado.
  const [originalFile, setOriginalFile] = useState<File | null>(null);
  const [cropSource, setCropSource] = useState<File | null>(null);
  const [logoFile, setLogoFile] = useState<File | null>(null);
  const [logoPreview, setLogoPreview] = useState("");
  const [dragging, setDragging] = useState(false);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    return () => { if (logoPreview) URL.revokeObjectURL(logoPreview); };
  }, [logoPreview]);

  const fetchData = async () => {
    try {
      const res = await fetch("/api/site/empresas.php");
      const data = await readJson(res);
      if (!res.ok || !data?.ok) throw new Error(errorMessage(data, res, "Erro ao carregar empresas."));
      setCompanies(data.companies as Company[]);
    } catch {
      setError("Erro ao carregar empresas.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    fetchData();
  }, []);

  const resetImage = () => {
    setOriginalFile(null);
    setCropSource(null);
    setLogoFile(null);
    setLogoPreview("");
    if (fileInputRef.current) fileInputRef.current.value = "";
  };

  const resetForm = () => {
    setEditingId(null);
    setNewName("");
    setNewLogoUrl("");
    setError("");
    resetImage();
  };

  const pickFile = (file: File | null | undefined) => {
    if (!file) return;
    setError("");
    setOriginalFile(file);
    setCropSource(file);
  };

  const handleCropApply = (blob: Blob) => {
    const file = new File([blob], "logo.png", { type: "image/png" });
    setLogoFile(file);
    setLogoPreview(URL.createObjectURL(file));
    setCropSource(null);
  };

  const handleCropCancel = () => {
    setCropSource(null);
    // Cancelou antes de ter uma logo: o arquivo escolhido é descartado.
    if (!logoFile) resetImage();
  };

  const handleCreate = async (e: FormEvent) => {
    e.preventDefault();
    setError("");
    setSuccessMsg("");
    if (!logoFile && !newLogoUrl.trim()) {
      setError("Envie a imagem da logo ou informe um caminho.");
      return;
    }
    setIsSubmitting(true);
    try {
      const form = new FormData();
      form.set("action", editingId === null ? "create" : "update");
      if (editingId !== null) form.set("id", String(editingId));
      form.set("name", newName.trim());
      form.set("logo_url", newLogoUrl.trim());
      if (logoFile) form.set("logo", logoFile);
      const res = await apiFetch("/api/site/empresas.php", { method: "POST", body: form });
      const data = await readJson(res);
      if (res.ok && data?.ok) {
        setSuccessMsg(editingId === null ? "Empresa cadastrada com sucesso!" : "Empresa atualizada com sucesso!");
        setIsCreating(false);
        resetForm();
        fetchData();
      } else {
        setError(errorMessage(data, res, "Erro ao salvar."));
      }
    } catch {
      setError("Erro de conexão.");
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleToggle = async (c: Company) => {
    if (busyId !== null) return;
    setBusyId(c.id);
    setError("");
    try {
      const res = await apiPostJson("/api/site/empresas.php", {
        action: "toggle_active", id: c.id, is_active: c.is_active ? 0 : 1
      });
      const data = await readJson(res);
      if (!res.ok || !data?.ok) throw new Error(errorMessage(data, res, "Erro ao alterar status."));
      setCompanies(current => current.map(company => company.id === c.id ? { ...company, is_active: data.is_active as number } : company));
    } catch (e) {
      setError(e instanceof Error ? e.message : "Erro de conexão.");
    } finally {
      setBusyId(null);
    }
  };

  const handleDelete = async () => {
    if (!deleteTarget || isDeleting) return;
    setIsDeleting(true);
    setError("");
    try {
      const res = await apiPostJson("/api/site/empresas.php", {
        action: "delete",
        id: deleteTarget.id
      });
      const data = await readJson(res);
      if (!res.ok || !data?.ok) throw new Error(errorMessage(data, res, "Erro ao excluir empresa."));
      setCompanies(current => current.filter(company => company.id !== deleteTarget.id));
      setDeleteTarget(null);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Erro de conexão.");
      setDeleteTarget(null);
    } finally {
      setIsDeleting(false);
    }
  };

  const startEdit = (c: Company) => {
    resetImage();
    setEditingId(c.id);
    setNewName(c.name);
    setNewLogoUrl(c.logo_url);
    setError("");
    setSuccessMsg("");
    setIsCreating(true);
  };

  if (loading) {
    return (
      <div className="flex items-center gap-3 p-6 text-sm text-[var(--color-text-on-dark)] sm:p-8">
        <span className="h-5 w-5 animate-spin rounded-full border-2 border-[var(--color-accent-soft)] border-t-[var(--color-accent)]" />
        Carregando...
      </div>
    );
  }

  const activeCount = companies.filter((c) => c.is_active).length;
  const currentLogoUrl = editingId !== null ? companies.find((c) => c.id === editingId)?.logo_url ?? "" : "";

  return (
    <div className="mx-auto max-w-6xl p-4 sm:p-6 lg:p-8">
      <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="min-w-0">
          <h1 className="text-2xl font-bold text-white">Empresas Parceiras</h1>
          <p className="mt-1 text-sm text-[var(--color-text-on-dark)]">
            Logomarcas do carrossel da home.
          </p>
        </div>
        <button
          type="button"
          onClick={() => {
            const next = !isCreating;
            setIsCreating(next);
            resetForm();
            setSuccessMsg("");
            if (!next) setError("");
          }}
          className={isCreating ? ghostButton : primaryButton}
        >
          {isCreating ? <CloseIcon width={16} height={16} /> : <PlusIcon width={16} height={16} />}
          {isCreating ? "Cancelar" : "Nova Empresa"}
        </button>
      </div>

      {successMsg && (
        <div role="status" className="mb-6 flex items-start justify-between gap-4 rounded-2xl border border-emerald-500/50 bg-emerald-950/60 p-4 text-emerald-200">
          <p className="text-sm font-semibold">{successMsg}</p>
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

      {error && (
        <div role="alert" className="mb-6 rounded-2xl border border-red-500/50 bg-red-950/60 p-4 text-sm text-red-200">
          {error}
        </div>
      )}

      {isCreating && (
        <form onSubmit={handleCreate} className="mb-6 rounded-2xl border border-[var(--color-border-dark)] bg-[rgba(255,255,255,0.04)] p-5 shadow-xl sm:p-6">
          <h2 className="mb-4 text-lg font-semibold text-white">{editingId === null ? "Nova Empresa" : "Editar Empresa"}</h2>

          <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div className="space-y-4">
              <label className={labelClass}>Nome da Empresa
                <input
                  value={newName} onChange={e => setNewName(e.target.value)} required
                  placeholder="Heineken"
                  className={inputClass}
                />
              </label>
              <label className={labelClass}>Caminho da Logo <span className="font-normal normal-case tracking-normal text-white/40">(opcional)</span>
                <input
                  value={newLogoUrl} onChange={e => setNewLogoUrl(e.target.value)}
                  placeholder="/logos/heineken.png"
                  className={`${inputClass} font-mono`}
                />
              </label>
            </div>

            <div>
              {logoFile && logoPreview ? (
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                  <LogoTile src={logoPreview} alt="Prévia da logo" className="h-24 w-full sm:w-60 [&>img]:max-h-14" />
                  <div className="flex flex-wrap gap-2">
                    <button
                      type="button"
                      onClick={() => originalFile && setCropSource(originalFile)}
                      className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-white/20"
                    >
                      <CropIcon width={14} height={14} />
                      Ajustar
                    </button>
                    <button
                      type="button"
                      onClick={resetImage}
                      className="inline-flex items-center gap-2 rounded-full px-4 py-2 text-xs font-semibold text-red-300 transition-colors hover:bg-red-500/15"
                    >
                      <TrashIcon width={14} height={14} />
                      Remover
                    </button>
                  </div>
                </div>
              ) : (
                <label
                  onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
                  onDragLeave={() => setDragging(false)}
                  onDrop={(e) => { e.preventDefault(); setDragging(false); pickFile(e.dataTransfer.files?.[0]); }}
                  className={`flex min-h-24 cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed px-4 py-5 text-center transition-colors ${
                    dragging
                      ? "border-[var(--color-accent)] bg-[var(--color-accent-soft)]"
                      : "border-[var(--color-border-dark)] hover:border-[var(--color-accent)]/60 hover:bg-white/5"
                  }`}
                >
                  <span className="flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white">
                    <UploadIcon width={18} height={18} />
                  </span>
                  <span className="text-sm font-semibold text-white">Imagem da Logo</span>
                  <span className="text-xs text-white/45">PNG, JPG, WebP ou SVG</span>
                  {currentLogoUrl && (
                    <LogoTile src={currentLogoUrl} alt="" className="mt-2 h-14 w-40 [&>img]:max-h-9" />
                  )}
                  <input
                    ref={fileInputRef}
                    type="file"
                    accept="image/*"
                    className="sr-only"
                    onChange={e => pickFile(e.target.files?.[0])}
                  />
                </label>
              )}
            </div>
          </div>

          <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:gap-3">
            <button type="button" onClick={() => { setIsCreating(false); resetForm(); }} className={ghostButton}>
              Cancelar
            </button>
            <button type="submit" disabled={isSubmitting} className={primaryButton}>
              {isSubmitting ? "Salvando..." : "Salvar Empresa"}
            </button>
          </div>
        </form>
      )}

      <section className="overflow-hidden rounded-2xl border border-[var(--color-border-dark)] bg-[rgba(255,255,255,0.03)] shadow-2xl">
        <div className="flex items-center justify-between gap-3 border-b border-[var(--color-border-dark)] p-4">
          <p className="text-sm text-[var(--color-text-on-dark)]">
            <strong className="font-semibold text-white">{companies.length}</strong>{" "}
            {companies.length === 1 ? "empresa" : "empresas"}
            <span className="text-white/50"> · {activeCount} no site</span>
          </p>
        </div>
        <div className="data-table-wrap">
          <table className="data-table text-white/85">
          <thead className="bg-black/40 text-white">
            <tr>
              <th>Empresa</th>
              <th>Caminho</th>
              <th>Status</th>
              <th className="text-right">Ações</th>
            </tr>
          </thead>
          <tbody>
            {companies.map(c => (
              <tr key={c.id} className={`transition-colors xl:hover:bg-white/5 ${c.is_active ? "" : "opacity-70"}`}>
                <td>
                  <div className="flex min-w-0 items-center gap-3">
                    <LogoTile src={c.logo_url} alt={c.name} className="h-12 w-24 shrink-0 [&>img]:max-h-8" />
                    <span className="truncate font-semibold text-white">{c.name}</span>
                  </div>
                </td>
                <td data-label="Caminho">
                  <span className="block max-w-[16rem] truncate font-mono text-xs text-white/60" title={c.logo_url}>{c.logo_url}</span>
                </td>
                <td data-label="Status">
                  <button
                    type="button"
                    disabled={busyId !== null}
                    onClick={() => handleToggle(c)}
                    title={c.is_active ? "Tirar do site" : "Mostrar no site"}
                    className={`inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold transition-colors disabled:opacity-60 ${
                      c.is_active
                        ? "border-emerald-500/30 bg-emerald-500/15 text-emerald-300 hover:bg-emerald-500/25"
                        : "border-white/15 bg-white/5 text-white/60 hover:bg-white/10"
                    }`}
                  >
                    <span className={`h-2 w-2 rounded-full ${c.is_active ? "bg-emerald-400" : "bg-white/40"}`} />
                    {busyId === c.id ? <span role="status" aria-label="Alterando status">Alterando...</span> : c.is_active ? "Ativo" : "Inativo"}
                  </button>
                </td>
                <td className="data-table-actions">
                  <div className="flex items-center justify-end gap-1.5">
                    <button
                      type="button"
                      aria-label={`Editar ${c.name}`}
                      title="Editar"
                      onClick={() => startEdit(c)}
                      className="flex h-9 w-9 items-center justify-center rounded-full border border-sky-500/30 text-sky-300 transition-colors hover:bg-sky-500/15 hover:text-white"
                    >
                      <PencilIcon width={16} height={16} />
                    </button>
                    <button
                      type="button"
                      aria-label={`Excluir ${c.name}`}
                      title="Excluir"
                      onClick={() => setDeleteTarget(c)}
                      className="flex h-9 w-9 items-center justify-center rounded-full border border-red-500/30 text-red-300 transition-colors hover:bg-red-500/15 hover:text-white"
                    >
                      <TrashIcon width={16} height={16} />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
            {companies.length === 0 && (
              <tr>
                <td colSpan={4} className="data-table-empty py-10 text-center text-sm text-white/60">
                  <span className="inline-flex items-center gap-2"><ImageIcon width={18} height={18} /> Nenhuma empresa cadastrada.</span>
                </td>
              </tr>
            )}
          </tbody>
          </table>
        </div>
      </section>

      {cropSource && (
        <LogoCropper
          key={`${cropSource.name}-${cropSource.size}-${cropSource.lastModified}`}
          file={cropSource}
          onCancel={handleCropCancel}
          onApply={handleCropApply}
        />
      )}

      {deleteTarget && (
        <DashboardModal
          title="Excluir Empresa"
          icon={<TrashIcon width={18} height={18} />}
          tone="danger"
          size="sm"
          onClose={() => setDeleteTarget(null)}
        >
          <p className="text-sm text-[var(--color-text-on-dark)]">
            Excluir <strong className="text-white">{deleteTarget.name}</strong> do carrossel?
          </p>
          <ModalActions>
            <button type="button" onClick={() => setDeleteTarget(null)} disabled={isDeleting} className={ghostButton}>
              Cancelar
            </button>
            <button
              type="button"
              onClick={handleDelete}
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
