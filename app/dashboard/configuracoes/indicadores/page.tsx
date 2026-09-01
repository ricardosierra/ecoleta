"use client";

import { useEffect, useState, FormEvent } from "react";
import { apiPostJson } from "@/lib/dashboard-api";

type Indicator = {
  key: string;
  value: string;
  label: string;
  symbol_type: string;
  symbol_value: string;
};

type HistoryLog = {
  id: number;
  indicator_key: string;
  old_value: string;
  new_value: string;
  changed_by_login: string;
  created_at: string;
  ip_address: string;
};

export default function IndicadoresPage() {
  const [indicators, setIndicators] = useState<Indicator[]>([]);
  const [history, setHistory] = useState<HistoryLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [successMsg, setSuccessMsg] = useState("");
  
  const [editTarget, setEditTarget] = useState<Indicator | null>(null);
  const [editValue, setEditValue] = useState("");
  const [editLabel, setEditLabel] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);

  const fetchData = async () => {
    try {
      const [resInd, resHist] = await Promise.all([
        fetch("/api/site/indicadores.php"),
        fetch("/api/site/indicadores_history.php")
      ]);
      
      if (resInd.ok) {
        const data = await resInd.json();
        if (data.ok) setIndicators(data.indicators);
      }
      
      if (resHist.ok) {
        const data = await resHist.json();
        if (data.ok) setHistory(data.history);
      }
    } catch {
      setError("Erro ao carregar dados.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    fetchData();
  }, []);

  const handleEdit = (ind: Indicator) => {
    setEditTarget(ind);
    setEditValue(ind.value);
    setEditLabel(ind.label);
    setError("");
  };

  const handleSave = async (e: FormEvent) => {
    e.preventDefault();
    if (!editTarget) return;
    
    setIsSubmitting(true);
    try {
      const res = await apiPostJson("/api/site/indicadores.php", {
        key: editTarget.key,
        value: editValue,
        label: editLabel,
      });
      const data = await res.json();
      if (res.ok && data.ok) {
        setSuccessMsg("Indicador atualizado com sucesso!");
        setEditTarget(null);
        fetchData();
      } else {
        setError(data.error || "Erro ao salvar.");
      }
    } catch {
      setError("Erro de conexão.");
    } finally {
      setIsSubmitting(false);
    }
  };

  if (loading) return <div className="p-8 text-white">Carregando...</div>;

  return (
    <div className="p-4 sm:p-8 max-w-6xl mx-auto h-full overflow-y-auto">
      <h1 className="text-2xl font-bold text-white mb-2">Indicadores do Site</h1>
      <p className="text-sm text-[var(--color-text-on-dark)] mb-8">
        Altere os números reais da operação exibidos na página ESG. O histórico mantém um registro de todas as mudanças.
      </p>

      {successMsg && (
        <div className="mb-6 p-4 bg-emerald-950/60 border border-emerald-500/50 rounded-2xl text-emerald-200 flex items-start justify-between">
          <p className="font-semibold text-sm">{successMsg}</p>
          <button onClick={() => setSuccessMsg("")} className="text-emerald-400 hover:text-white">✕</button>
        </div>
      )}
      
      {error && !editTarget && (
        <div className="mb-6 p-4 bg-red-950/60 border border-red-500/50 rounded-2xl text-red-200">
          {error}
        </div>
      )}

      <div className="bg-[rgba(255,255,255,0.03)] border border-[var(--color-border-dark)] rounded-2xl overflow-hidden shadow-2xl mb-8">
        <table className="w-full text-left text-sm text-white/80">
          <thead className="bg-black/40 text-white border-b border-[var(--color-border-dark)] text-xs uppercase tracking-wider">
            <tr>
              <th className="px-6 py-4 font-semibold">Chave</th>
              <th className="px-6 py-4 font-semibold">Valor</th>
              <th className="px-6 py-4 font-semibold">Rótulo</th>
              <th className="px-6 py-4 font-semibold text-right">Ação</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[var(--color-border-dark)]">
            {indicators.map((ind) => (
              <tr key={ind.key} className="hover:bg-white/5 transition-colors">
                <td className="px-6 py-4 font-medium text-white">{ind.key}</td>
                <td className="px-6 py-4 text-emerald-400 font-bold text-lg">{ind.value}</td>
                <td className="px-6 py-4">{ind.label}</td>
                <td className="px-6 py-4 text-right">
                  <button
                    onClick={() => handleEdit(ind)}
                    className="text-xs font-medium px-3 py-1.5 rounded-full border border-blue-500/40 text-blue-300 hover:bg-blue-500/20 transition-colors"
                  >
                    ✏️ Editar
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <h2 className="text-xl font-bold text-white mb-4">Histórico de Alterações</h2>
      <div className="bg-[rgba(255,255,255,0.03)] border border-[var(--color-border-dark)] rounded-2xl overflow-hidden shadow-2xl">
        <table className="w-full text-left text-sm text-white/80">
          <thead className="bg-black/40 text-white border-b border-[var(--color-border-dark)] text-xs uppercase tracking-wider">
            <tr>
              <th className="px-6 py-4 font-semibold">Data / Hora</th>
              <th className="px-6 py-4 font-semibold">Indicador</th>
              <th className="px-6 py-4 font-semibold">Alteração</th>
              <th className="px-6 py-4 font-semibold">Responsável</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[var(--color-border-dark)]">
            {history.map((h) => (
              <tr key={h.id} className="hover:bg-white/5 transition-colors">
                <td className="px-6 py-4 text-xs">{new Date(h.created_at).toLocaleString("pt-BR")}</td>
                <td className="px-6 py-4 font-medium text-white">{h.indicator_key}</td>
                <td className="px-6 py-4">
                  <span className="line-through text-red-300 mr-2">{h.old_value}</span>
                  <span className="text-emerald-400 font-bold">→ {h.new_value}</span>
                </td>
                <td className="px-6 py-4 text-xs">
                  {h.changed_by_login} <br/>
                  <span className="text-white/40">{h.ip_address}</span>
                </td>
              </tr>
            ))}
            {history.length === 0 && (
              <tr><td colSpan={4} className="px-6 py-8 text-center text-white/60">Sem histórico de alterações.</td></tr>
            )}
          </tbody>
        </table>
      </div>

      {editTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
          <form onSubmit={handleSave} className="w-full max-w-lg bg-[#0D1F0F] border border-[var(--color-border-dark)] rounded-3xl p-6 shadow-2xl">
            <h3 className="text-xl font-bold text-white mb-4">Editar {editTarget.key}</h3>
            
            <div className="space-y-4 mb-6">
              <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)] mb-1">
                Valor (Símbolo/Número)
                <input
                  value={editValue}
                  onChange={(e) => setEditValue(e.target.value)}
                  required
                  className="mt-1 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/40 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
                />
              </label>

              <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)] mb-1">
                Rótulo/Descrição
                <input
                  value={editLabel}
                  onChange={(e) => setEditLabel(e.target.value)}
                  required
                  className="mt-1 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/40 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
                />
              </label>
            </div>
            
            {error && <p className="text-xs text-red-300 mb-4">{error}</p>}

            <div className="flex justify-end gap-3">
              <button
                type="button"
                onClick={() => setEditTarget(null)}
                className="px-5 py-2.5 rounded-full text-sm text-white/70 hover:text-white transition-colors"
              >
                Cancelar
              </button>
              <button
                type="submit"
                disabled={isSubmitting}
                className="bg-blue-600 hover:bg-blue-500 text-white px-6 py-2.5 rounded-full text-sm font-semibold transition-opacity"
              >
                {isSubmitting ? "Salvando..." : "Salvar"}
              </button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}
