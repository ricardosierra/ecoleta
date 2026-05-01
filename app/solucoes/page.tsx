import type { Metadata } from "next";
import Section, { SectionHeader } from "@/components/Section";
import Card from "@/components/Card";
import Button from "@/components/Button";
import Reveal from "@/components/Reveal";
import PageHero from "@/components/PageHero";
import ProcessSteps from "@/components/ProcessSteps";
import TiltCard from "@/components/TiltCard";
import {
  ArrowRightIcon,
  ClipboardIcon,
  LeafIcon,
  SettingsIcon,
} from "@/components/icons";

export const metadata: Metadata = {
  title: "Soluções",
  description:
    "Gestão completa de resíduos, do planejamento à comprovação. Diagnóstico, PGRS, supervisão técnica, MTR, CDF e Relatório ESG.",
};

const steps = [
  { title: "Diagnóstico", desc: "Levantamento da operação e dos resíduos gerados" },
  { title: "Planejamento", desc: "Elaboração do PGRS e estruturação dos pontos" },
  { title: "Implementação", desc: "Instalação, treinamento e configuração" },
  { title: "Operação", desc: "Supervisão técnica e controle contínuo" },
  { title: "Destinação", desc: "Envio correto por fração com rastreabilidade" },
  { title: "Relatórios", desc: "Indicadores e documentação para auditoria" },
];

const pilares = [
  {
    icon: SettingsIcon,
    title: "Gestão completa",
    desc: "Planejamento, estruturação e operação com supervisão técnica no local.",
    items: [
      "Diagnóstico e PGRS",
      "Supervisão presencial",
      "Organização de caçambas",
      "Controle de pontos de descarte",
    ],
  },
  {
    icon: LeafIcon,
    title: "ESG aplicado",
    desc: "Indicadores ambientais, impacto social e relatórios estruturados para divulgação.",
    items: [
      "Indicadores de desvio de aterro",
      "Compensação de carbono",
      "Relatório ESG mensal",
      "Alinhado à ISO 14001",
    ],
  },
  {
    icon: ClipboardIcon,
    title: "Documentação ambiental",
    desc: "Toda a documentação exigida pela legislação para conformidade e auditoria.",
    items: [
      "PGRS",
      "MTR (Manifesto de Transporte)",
      "CDF (Certificado de Destinação)",
      "Relatório ESG",
    ],
  },
];

const residuos = [
  { name: "Recicláveis", tag: "Papel · Plástico · Metal · Vidro", color: "bg-(--color-accent)" },
  { name: "Orgânicos", tag: "Compostagem", color: "bg-emerald-300" },
  { name: "Rejeitos", tag: "Destinação adequada", color: "bg-zinc-400" },
  { name: "Infectantes", tag: "Licença específica", color: "bg-rose-400" },
  { name: "Têxteis", tag: "Doação · Reuso", color: "bg-amber-400" },
];

export default function SolucoesPage() {
  return (
    <>
      <PageHero
        eyebrow="Soluções"
        title={
          <>
            Gestão completa de resíduos, do{" "}
            <span className="text-(--color-accent)">planejamento</span> à
            comprovação
          </>
        }
        subtitle="A Ecoleta estrutura, acompanha e comprova cada etapa da operação, reduzindo riscos e aumentando o controle ambiental."
      >
        <Button
          href="/contato"
          variant="primary"
          iconRight={<ArrowRightIcon width={18} height={18} />}
        >
          Solicitar diagnóstico
        </Button>
      </PageHero>

      {/* Como funciona */}
      <Section tone="white" id="como-funciona">
        <Reveal>
          <SectionHeader
            eyebrow="Como funciona"
            title="Da primeira visita ao relatório final"
          />
        </Reveal>
        <Reveal delay={120}>
          <ProcessSteps steps={steps} />
        </Reveal>
      </Section>

      {/* Três pilares */}
      <Section tone="light" id="entregamos">
        <Reveal>
          <SectionHeader
            eyebrow="O que entregamos"
            title="Três pilares em uma operação"
          />
        </Reveal>
        <ul className="grid gap-6 md:grid-cols-3">
          {pilares.map(({ icon: Icon, title, desc, items }, i) => (
            <Reveal key={title} as="li" delay={i * 100}>
              <TiltCard intensity={4}>
                <Card tone="white" className="h-full border-l-4 border-(--color-accent)">
                  <div className="size-12 rounded-full bg-(--color-accent-soft) text-(--color-secondary) flex items-center justify-center mb-5">
                    <Icon width={22} height={22} />
                  </div>
                  <h3 className="card-title mb-3">{title}</h3>
                  <p className="text-sm text-(--color-text-muted) leading-relaxed mb-4">
                    {desc}
                  </p>
                  <ul className="space-y-2">
                    {items.map((it) => (
                      <li
                        key={it}
                        className="flex items-start gap-2 text-sm text-(--color-secondary)"
                      >
                        <span className="mt-1.5 size-1.5 rounded-full bg-(--color-accent) shrink-0" />
                        {it}
                      </li>
                    ))}
                  </ul>
                </Card>
              </TiltCard>
            </Reveal>
          ))}
        </ul>
      </Section>

      {/* Resíduos atendidos */}
      <Section tone="dark" id="residuos">
        <Reveal>
          <SectionHeader
            eyebrow="Resíduos atendidos"
            title="Frações que gerenciamos"
            tone="dark"
          />
        </Reveal>
        <ul className="flex flex-wrap gap-3">
          {residuos.map((r, i) => (
            <Reveal key={r.name} as="li" delay={i * 70}>
              <div className="inline-flex items-center gap-3 px-5 py-3 rounded-full bg-[#0a1810] border border-(--color-border-dark)">
                <span className={`size-2.5 rounded-full ${r.color}`} />
                <span className="font-semibold">{r.name}</span>
                <span className="text-xs px-2 py-1 rounded-full bg-white/5 text-white/70">
                  {r.tag}
                </span>
              </div>
            </Reveal>
          ))}
        </ul>
      </Section>

      {/* Diferencial operacional */}
      <Section tone="light" id="diferencial-operacional">
        <Reveal>
          <p className="eyebrow text-(--color-secondary) flex items-center gap-2 mb-3">
            <span className="inline-block size-1.5 rounded-full bg-current" />
            Diferencial operacional
          </p>
        </Reveal>
        <Reveal delay={80}>
          <div className="rounded-[10px] bg-(--color-bg-dark) text-white p-8 md:p-12 grid gap-10 lg:grid-cols-[1.4fr_1fr]">
            <div>
              <h2 className="section-title">Controle real da operação</h2>
              <p className="mt-4 text-white/80 leading-relaxed max-w-xl">
                A Ecoleta atua diretamente na operação, com supervisão técnica
                garantindo organização, separação correta e redução de erros
                nos pontos de descarte.
              </p>
              <div className="mt-8 flex flex-wrap gap-3">
                {["Menos mistura", "Menos desperdício", "Mais controle"].map(
                  (item) => (
                    <span
                      key={item}
                      className="inline-flex items-center gap-2 px-4 py-2 rounded-full border border-white/15 text-sm"
                    >
                      <ArrowRightIcon width={14} height={14} />
                      {item}
                    </span>
                  )
                )}
              </div>
            </div>
            <div className="grid gap-3">
              <StatCard value="68%" label="desvio de aterro" />
              <StatCard value="100%" label="rastreabilidade" />
              <StatCard value="PNRS" label="conformidade total" />
            </div>
          </div>
        </Reveal>
      </Section>

      {/* CTA */}
      <section className="bg-(--color-accent) py-16">
        <div className="container-page text-center">
          <Reveal>
            <h2 className="text-3xl md:text-4xl font-bold text-(--color-bg-dark) tracking-tight">
              Pronto para organizar sua operação?
            </h2>
            <p className="mt-3 text-(--color-bg-dark)/80">
              Solicite um diagnóstico e veja o que a Ecoleta pode entregar para
              sua empresa.
            </p>
            <div className="mt-7">
              <Button href="/contato" variant="dark" iconRight={<ArrowRightIcon width={18} height={18} />}>
                Falar com especialista
              </Button>
            </div>
          </Reveal>
        </div>
      </section>
    </>
  );
}

function StatCard({ value, label }: { value: string; label: string }) {
  return (
    <div className="rounded-[10px] bg-[#0a1810] border border-(--color-border-dark) px-6 py-5 flex flex-col items-center justify-center text-center">
      <p className="text-3xl font-bold text-(--color-accent) leading-tight">
        {value}
      </p>
      <p className="text-xs uppercase tracking-widest text-white/50 mt-1">
        {label}
      </p>
    </div>
  );
}

