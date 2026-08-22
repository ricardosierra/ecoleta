"use client";

import { useEffect, useState, FormEvent } from "react";
import { DashboardGate, useDashboardAuth } from "@/components/DashboardGate";
import Link from "next/link";

type Indicator = {
  id: number;
  key_name: string;
  label: string;
  value: string;
  numeric_value: number | null;
  category: string;
  order_index: number;
  updated_at?: string;
};

export default function IndicadoresPage() {
  return (
    <DashboardGate>
      <IndicadoresManager />
    </DashboardGate>
  );
}

function IndicadoresManager() {
  const { user: currentUser } = useDashboardAuth();
  const [indicators, setIndicators] = useState<Indicator[]>([]);
  const [formValues, setFormValues] = useState<Record<string, { value: string; numeric_value?: number | null; label: string }>>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [successMsg, setSuccessMsg] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [activeTab, setActiveTab] = useState<"home" | "esg">("home");

  const fetchIndicators = () => {
    fetch("/api/indicators/index.php")
      .then((res) => {
        if (!res.ok) throw new Error("Erro ao carregar indicadores.");
        return res.json();
      })
      .then((data) => {
        if (data.ok && Array.isArray(data.indicators)) {
          setIndicators(data.indicators);
          const initialMap: Record<string, { value: string; numeric_value?: number | null; label: string }> = {};
          data.indicators.forEach((ind: Indicator) => {
            initialMap[ind.key_name] = {
              value: ind.value,
              numeric_value: ind.numeric_value,
              label: ind.label,
            };
          });
          setFormValues(initialMap);
        }
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    fetchIndicators();
  }, []);

  const isAdmin = currentUser?.role === "root" || currentUser?.role === "master";

  if (!loading && !isAdmin) {
    return (
      <div className="p-8 text-center text-white">
        <div className="max-w-md mx-auto p-8 rounded-3xl bg-[rgba(255,255,255,0.03)] border border-red-500/30">
          <p className="text-3xl mb-3">🔒</p>
          <h2 className="text-xl font-bold text-white mb-2">Acesso Restrito</h2>
          <p className="text-sm text-[var(--color-text-on-dark)] mb-6">
            Apenas usuários com perfil <strong>Root</strong> ou <strong>Master</strong> têm permissão para configurar os indicadores do site.
          </p>
          <Link
            href="/dashboard"
            className="inline-block bg-[var(--color-accent)] text-black px-6 py-2.5 rounded-full text-sm font-semibold hover:opacity-90"
          >
            Voltar ao Dashboard
          </Link>
        </div>
      </div>
    );
  }

  const handleValueChange = (keyName: string, newValue: string, numericVal?: number | null) => {
    setFormValues((prev) => ({
      ...prev,
      [keyName]: {
        ...prev[keyName],
        value: newValue,
        numeric_value: numericVal !== undefined ? numericVal : prev[keyName]?.numeric_value,
      },
    }));
  };

  const handleSave = async (e: FormEvent) => {
    e.preventDefault();
    setError("");
    setSuccessMsg("");
    setIsSubmitting(true);

    const payload = Object.entries(formValues).map(([key_name, data]) => ({
      key_name,
      value: data.value,
      numeric_value: data.numeric_value,
      label: data.label,
    }));

    try {
      const res = await fetch("/api/indicators/index.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ indicators: payload }),
      });
      const data = await res.json();

      if (res.ok && data.ok) {
        setSuccessMsg("Indicadores e métricas atualizados com sucesso!");
        fetchIndicators();
      } else {
        setError(data.error || "Erro ao salvar alterações nos indicadores.");
      }
    } catch {
      setError("Erro de conexão ao salvar indicadores.");
    } finally {
      setIsSubmitting(false);
    }
  };

  const homeIndicators = indicators.filter((i) => i.category === "home");
  const esgIndicators = indicators.filter((i) => i.category === "esg");

  if (loading) return <div className="p-8 text-white">Carregando indicadores...</div>;

  return (
    <div className="p-4 sm:p-8 max-w-6xl mx-auto h-full overflow-y-auto">
      {/* Cabeçalho */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
        <div>
          <h1 className="text-2xl font-bold text-white">Gestão de Indicadores & Métricas</h1>
          <p className="text-sm text-[var(--color-text-on-dark)] mt-1">
            Personalize os números, estatísticas e porcentagens exibidas nas páginas Institucionais e de ESG.
          </p>
        </div>
        <div className="flex items-center gap-3">
          <button
            onClick={handleSave}
            disabled={isSubmitting}
            className="bg-[var(--color-accent)] text-black px-6 py-2.5 rounded-full text-sm font-semibold hover:opacity-90 transition-opacity flex items-center gap-2 shadow-md cursor-pointer disabled:opacity-50"
          >
            {isSubmitting ? "Salvando..." : "💾 Salvar Todas as Alterações"}
          </button>
        </div>
      </div>

      {/* Mensagens de Sucesso e Erro */}
      {successMsg && (
        <div className="mb-6 p-4 bg-emerald-950/60 border border-emerald-500/50 rounded-2xl text-emerald-200 flex items-start justify-between">
          <div>
            <p className="font-semibold text-sm">{successMsg}</p>
            <p className="text-xs mt-1 text-emerald-300/80">
              As páginas do site já estão exibindo os novos valores configurados.
            </p>
          </div>
          <button onClick={() => setSuccessMsg("")} className="text-emerald-400 hover:text-white text-sm font-bold ml-4 cursor-pointer">
            ✕
          </button>
        </div>
      )}

      {error && (
        <div className="mb-6 p-4 bg-red-950/60 border border-red-500/50 rounded-2xl text-red-200">
          {error}
        </div>
      )}

      {/* Abas */}
      <div className="flex border-b border-white/10 gap-4 mb-8">
        <button
          type="button"
          onClick={() => setActiveTab("home")}
          className={`pb-3 px-2 text-sm font-semibold transition-colors cursor-pointer border-b-2 flex items-center gap-2 ${
            activeTab === "home"
              ? "border-[var(--color-accent)] text-[var(--color-accent)]"
              : "border-transparent text-white/60 hover:text-white"
          }`}
        >
          <span>🏠</span> Página Inicial (Home)
          <span className="text-xs px-2 py-0.5 rounded-full bg-white/10 text-white/80">{homeIndicators.length}</span>
        </button>
        <button
          type="button"
          onClick={() => setActiveTab("esg")}
          className={`pb-3 px-2 text-sm font-semibold transition-colors cursor-pointer border-b-2 flex items-center gap-2 ${
            activeTab === "esg"
              ? "border-[var(--color-accent)] text-[var(--color-accent)]"
              : "border-transparent text-white/60 hover:text-white"
          }`}
        >
          <span>🌱</span> Página ESG & Impacto (/esg)
          <span className="text-xs px-2 py-0.5 rounded-full bg-white/10 text-white/80">{esgIndicators.length}</span>
        </button>
      </div>

      {/* Conteúdo da Aba */}
      <form onSubmit={handleSave}>
        {activeTab === "home" && (
          <div className="space-y-8">
            {/* Seção Gráfico Donut Home */}
            <div className="p-6 bg-[rgba(255,255,255,0.03)] border border-[var(--color-border-dark)] rounded-3xl">
              <h2 className="text-lg font-bold text-white flex items-center gap-2 mb-2">
                <span>🎯</span> Gráfico Central de Desvio de Aterro
              </h2>
              <p className="text-xs text-[var(--color-text-on-dark)] mb-6">
                Define a porcentagem do gráfico circular animado e o destaque da seção de resultados.
              </p>
              <div className="grid gap-6 sm:grid-cols-2">
                <div>
                  <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)] mb-1">
                    Porcentagem Numérica (0 a 100)
                  </label>
                  <input
                    type="number"
                    min="0"
                    max="100"
                    value={formValues["home_donut_desvio"]?.numeric_value ?? 92}
                    onChange={(e) => {
                      const num = Number(e.target.value);
                      handleValueChange("home_donut_desvio", `${num}%`, num);
                      handleValueChange("home_menos_aterro", `${num}%`, num);
                    }}
                    className="w-full rounded-xl border border-[var(--color-border-dark)] bg-black/40 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)] font-mono"
                  />
                  <p className="text-[11px] text-white/40 mt-1">
                    Atualiza automaticamente o gráfico circular animado e a métrica &quot;Menos envio ao aterro&quot;.
                  </p>
                </div>
                <div className="p-4 bg-black/40 rounded-2xl border border-white/10 flex items-center justify-center text-center">
                  <div>
                    <span className="text-3xl font-bold text-[var(--color-accent)] font-mono">
                      {formValues["home_donut_desvio"]?.value || "92%"}
                    </span>
                    <p className="text-xs uppercase tracking-widest text-white/60 mt-1">desvio de aterro</p>
                  </div>
                </div>
              </div>
            </div>

            {/* Seção Resultados e Métricas da Home */}
            <div className="p-6 bg-[rgba(255,255,255,0.03)] border border-[var(--color-border-dark)] rounded-3xl">
              <h2 className="text-lg font-bold text-white flex items-center gap-2 mb-2">
                <span>📊</span> Cards de Resultados & Diferenciais
              </h2>
              <p className="text-xs text-[var(--color-text-on-dark)] mb-6">
                Indicadores exibidos nos cards da seção de resultados da página inicial.
              </p>
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {homeIndicators
                  .filter((i) => !["home_donut_desvio"].includes(i.key_name))
                  .map((ind) => (
                    <div key={ind.key_name} className="p-4 bg-black/30 border border-white/10 rounded-2xl">
                      <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-accent)] mb-2">
                        {ind.label}
                      </label>
                      <input
                        value={formValues[ind.key_name]?.value ?? ind.value}
                        onChange={(e) => handleValueChange(ind.key_name, e.target.value)}
                        className="w-full rounded-xl border border-[var(--color-border-dark)] bg-black/50 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)] font-medium"
                      />
                      <div className="mt-3 text-right">
                        <span className="text-xs text-white/40">Exibição: </span>
                        <strong className="text-xs text-white font-mono">{formValues[ind.key_name]?.value ?? ind.value}</strong>
                      </div>
                    </div>
                  ))}
              </div>
            </div>
          </div>
        )}

        {activeTab === "esg" && (
          <div className="space-y-8">
            {/* Seção Donut ESG */}
            <div className="p-6 bg-[rgba(255,255,255,0.03)] border border-[var(--color-border-dark)] rounded-3xl">
              <h2 className="text-lg font-bold text-white flex items-center gap-2 mb-2">
                <span>🎯</span> Gráfico Central ESG (Carbono Evitado)
              </h2>
              <div className="grid gap-6 sm:grid-cols-2">
                <div>
                  <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)] mb-1">
                    Porcentagem Numérica (0 a 100)
                  </label>
                  <input
                    type="number"
                    min="0"
                    max="100"
                    value={formValues["esg_donut_carbono"]?.numeric_value ?? 92}
                    onChange={(e) => {
                      const num = Number(e.target.value);
                      handleValueChange("esg_donut_carbono", `${num}%`, num);
                    }}
                    className="w-full rounded-xl border border-[var(--color-border-dark)] bg-black/40 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)] font-mono"
                  />
                </div>
                <div className="p-4 bg-black/40 rounded-2xl border border-white/10 flex items-center justify-center text-center">
                  <div>
                    <span className="text-3xl font-bold text-[var(--color-accent)] font-mono">
                      {formValues["esg_donut_carbono"]?.value || "92%"}
                    </span>
                    <p className="text-xs uppercase tracking-widest text-white/60 mt-1">carbono evitado</p>
                  </div>
                </div>
              </div>
            </div>

            {/* Seção Indicadores de Impacto */}
            <div className="p-6 bg-[rgba(255,255,255,0.03)] border border-[var(--color-border-dark)] rounded-3xl">
              <h2 className="text-lg font-bold text-white flex items-center gap-2 mb-2">
                <span>🌿</span> Indicadores de Impacto Ambiental & Social
              </h2>
              <p className="text-xs text-[var(--color-text-on-dark)] mb-6">
                Altere os valores acumulados de impacto exibidos na página de ESG (ex: 300 Mil, 190 tCO₂e, 1.300, etc.).
              </p>
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {esgIndicators
                  .filter((i) => i.key_name !== "esg_donut_carbono")
                  .map((ind) => (
                    <div key={ind.key_name} className="p-4 bg-black/30 border border-white/10 rounded-2xl">
                      <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-accent)] mb-2">
                        {ind.label}
                      </label>
                      <input
                        value={formValues[ind.key_name]?.value ?? ind.value}
                        onChange={(e) => handleValueChange(ind.key_name, e.target.value)}
                        className="w-full rounded-xl border border-[var(--color-border-dark)] bg-black/50 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)] font-medium"
                      />
                      <div className="mt-3 text-right">
                        <span className="text-xs text-white/40">Exibição: </span>
                        <strong className="text-xs text-white font-mono">{formValues[ind.key_name]?.value ?? ind.value}</strong>
                      </div>
                    </div>
                  ))}
              </div>
            </div>
          </div>
        )}

        <div className="mt-8 flex justify-end">
          <button
            type="submit"
            disabled={isSubmitting}
            className="bg-[var(--color-accent)] text-black px-8 py-3 rounded-full text-sm font-semibold hover:opacity-90 transition-opacity flex items-center gap-2 shadow-xl cursor-pointer disabled:opacity-50"
          >
            {isSubmitting ? "Salvando Alterações..." : "💾 Salvar Todas as Alterações"}
          </button>
        </div>
      </form>
    </div>
  );
}
