"use client";

import { useEffect, useState } from "react";
import { DashboardGate, useDashboardAuth } from "@/components/DashboardGate";
import { isAdmin } from "@/lib/authz";
import Logo from "@/components/Logo";

type Client = { id: number; name: string };
type OS = {
  id: number;
  client_name: string;
  weight: string;
  collection_date: string;
  bags_count: string;
  containers_count: string;
  responsible: string;
  signature_text: string;
};

export default function OSPage() {
  return (
    <DashboardGate>
      <OSMain />
    </DashboardGate>
  );
}

function OSMain() {
  const { user } = useDashboardAuth();
  const [clients, setClients] = useState<Client[]>([]);
  const [history, setHistory] = useState<OS[]>([]);
  const isUserAdmin = isAdmin(user);
  
  // Form fields
  const [clientId, setClientId] = useState("");
  const [weight, setWeight] = useState("");
  const [collectionDate, setCollectionDate] = useState("");
  const [bagsCount, setBagsCount] = useState("");
  const [containersCount, setContainersCount] = useState("");
  const [responsible, setResponsible] = useState("");
  
  const [activeOS, setActiveOS] = useState<OS | null>(null);

  useEffect(() => {
    if (!isUserAdmin) return;
    fetch("/api/clients/index.php").then(r => r.json()).then(d => { if(d.ok) setClients(d.clients); });
    fetch("/api/os/index.php").then(r => r.json()).then(d => { if(d.ok) setHistory(d.service_orders); });
  }, [isUserAdmin]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!clientId) return;
    
    const payload = {
      client_id: parseInt(clientId),
      weight,
      collection_date: collectionDate,
      bags_count: bagsCount,
      containers_count: containersCount,
      responsible
    };
    
    try {
      const res = await fetch("/api/os/index.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (res.ok && data.ok) {
        // Find client name
        const cName = clients.find(c => c.id === parseInt(clientId))?.name || "";
        const newOS: OS = {
          id: data.id,
          client_name: cName,
          ...payload,
          signature_text: "Responsável Técnica - ECOLEVA"
        };
        setHistory([newOS, ...history]);
        setActiveOS(newOS);
        
        // Clear form
        setWeight("");
        setCollectionDate("");
        setBagsCount("");
        setContainersCount("");
        setResponsible("");
      }
    } catch {}
  };

  const handlePrint = () => {
    window.print();
  };

  if (!isUserAdmin) return <div className="p-8 text-white">Acesso negado.</div>;

  return (
    <div className="max-w-6xl mx-auto p-6 sm:p-8 space-y-8 text-white">
      <div className="print:hidden">
        <h1 className="text-3xl font-bold">Ordem de Serviço (OS)</h1>
        <p className="text-[var(--color-text-on-dark)] mt-2">
          Gere OS de coleta para clientes fixos.
        </p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 print:hidden">
        {/* Formulário */}
        <div className="bg-[rgba(255,255,255,0.04)] rounded-2xl border border-[var(--color-border-dark)] p-6">
          <h2 className="text-xl font-semibold mb-4">Gerar Nova OS</h2>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]">Cliente *</label>
              <select required value={clientId} onChange={e => setClientId(e.target.value)} className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]">
                <option value="">Selecione...</option>
                {clients.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]">Data da Coleta</label>
                <input type="date" value={collectionDate} onChange={e => setCollectionDate(e.target.value)} className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
              </div>
              <div>
                <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]">Pesagem</label>
                <input value={weight} onChange={e => setWeight(e.target.value)} placeholder="Ex: 150 kg" className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
              </div>
              <div>
                <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]">Qtd. Sacos</label>
                <input type="number" value={bagsCount} onChange={e => setBagsCount(e.target.value)} className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
              </div>
              <div>
                <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]">Qtd. Contêineres</label>
                <input type="number" value={containersCount} onChange={e => setContainersCount(e.target.value)} className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
              </div>
            </div>
            <div>
              <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]">Responsável pela Coleta</label>
              <input value={responsible} onChange={e => setResponsible(e.target.value)} className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
            </div>
            <button type="submit" className="w-full bg-[var(--color-accent)] text-[var(--color-bg-dark)] px-5 py-3 rounded-full font-semibold hover:opacity-90 transition mt-2">
              Gerar OS
            </button>
          </form>
        </div>

        {/* Pré-visualização e Ações */}
        <div>
          {activeOS ? (
            <div className="space-y-4">
              <div className="flex justify-between items-center">
                <h2 className="text-xl font-semibold">OS Gerada</h2>
                <button onClick={handlePrint} className="bg-white/10 hover:bg-white/20 px-4 py-2 rounded-full text-sm font-medium transition">
                  🖨️ Imprimir / Salvar PDF
                </button>
              </div>
              
              {/* Box que será impresso. Uso CSS inline para garantir layout limpo na impressão se preciso, mas Tailwind lida bem com @media print */}
              <div id="os-print-area" className="bg-white text-black p-8 rounded-lg shadow-xl relative">
                <div className="flex justify-between items-start border-b-2 border-black/10 pb-6 mb-6">
                  <Logo variant="dark" height={40} />
                  <div className="text-right">
                    <h3 className="text-2xl font-bold text-[var(--color-secondary)] uppercase tracking-wider">Ordem de Serviço</h3>
                    <p className="text-sm text-gray-500 font-mono mt-1">Nº {String(activeOS.id).padStart(5, '0')}</p>
                  </div>
                </div>
                
                <div className="space-y-4 text-base">
                  <p><span className="font-semibold text-gray-700">Cliente:</span> {activeOS.client_name}</p>
                  <p><span className="font-semibold text-gray-700">Data da Coleta:</span> {activeOS.collection_date ? new Date(activeOS.collection_date).toLocaleDateString('pt-BR') : '-'}</p>
                  <div className="grid grid-cols-2 gap-4">
                    <p><span className="font-semibold text-gray-700">Pesagem:</span> {activeOS.weight || '-'}</p>
                    <p><span className="font-semibold text-gray-700">Responsável:</span> {activeOS.responsible || '-'}</p>
                    <p><span className="font-semibold text-gray-700">Qtd. Sacos:</span> {activeOS.bags_count || '-'}</p>
                    <p><span className="font-semibold text-gray-700">Qtd. Contêineres:</span> {activeOS.containers_count || '-'}</p>
                  </div>
                </div>

                <div className="mt-20 pt-10 text-center">
                  <div className="w-64 border-t-2 border-black/30 mx-auto mb-2"></div>
                  <p className="font-semibold text-gray-800">{activeOS.signature_text}</p>
                </div>
              </div>
            </div>
          ) : (
            <div className="h-full border-2 border-dashed border-[var(--color-border-dark)] rounded-2xl flex items-center justify-center text-white/30 p-6 text-center">
              Preencha o formulário ao lado para gerar a OS. A visualização aparecerá aqui.
            </div>
          )}
        </div>
      </div>
      
      {/* Histórico */}
      <div className="bg-[rgba(255,255,255,0.04)] rounded-2xl border border-[var(--color-border-dark)] overflow-hidden print:hidden mt-8">
        <div className="p-4 border-b border-[var(--color-border-dark)]">
          <h3 className="font-semibold">Histórico de OS Geradas</h3>
        </div>
        <table className="w-full text-left text-sm whitespace-nowrap">
          <thead className="bg-black/40 text-[var(--color-text-on-dark)] border-b border-[var(--color-border-dark)]">
            <tr>
              <th className="px-6 py-3 font-medium">Nº</th>
              <th className="px-6 py-3 font-medium">Cliente</th>
              <th className="px-6 py-3 font-medium">Data</th>
              <th className="px-6 py-3 font-medium">Ações</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[var(--color-border-dark)]">
            {history.length === 0 ? (
              <tr><td colSpan={4} className="px-6 py-4 text-center text-white/50">Nenhuma OS encontrada.</td></tr>
            ) : history.map(os => (
              <tr key={os.id} className="hover:bg-white/5">
                <td className="px-6 py-3">#{String(os.id).padStart(5, '0')}</td>
                <td className="px-6 py-3">{os.client_name}</td>
                <td className="px-6 py-4">{os.containers_count} caçambas</td>
                <td className="px-6 py-3">{os.collection_date ? new Date(os.collection_date).toLocaleDateString('pt-BR') : '-'}</td>
                <td className="px-6 py-3">
                  <button onClick={() => setActiveOS(os)} className="text-[var(--color-accent)] hover:underline">Visualizar</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Estilos para impressão */}
      <style dangerouslySetInnerHTML={{__html: `
        @media print {
          body * { visibility: hidden; }
          #os-print-area, #os-print-area * { visibility: visible; }
          #os-print-area { position: absolute; left: 0; top: 0; width: 100%; box-shadow: none; }
        }
      `}} />
    </div>
  );
}
