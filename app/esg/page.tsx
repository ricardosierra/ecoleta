import type { Metadata } from "next";
import { createPageMetadata } from "@/lib/seo";
import Section, { SectionHeader, Eyebrow } from "@/components/Section";
import Card from "@/components/Card";
import Button from "@/components/Button";
import Reveal from "@/components/Reveal";
import PageHero from "@/components/PageHero";
import DonutChart from "@/components/DonutChart";
import LeafDecor from "@/components/LeafDecor";
import TiltCard from "@/components/TiltCard";
import {
  ArrowRightIcon,
  CarIcon,
  ClipboardIcon,
  DropletIcon,
  FileTextIcon,
  LeafIcon,
  RecycleIcon,
  SeedlingIcon,
  ShieldIcon,
  UsersIcon,
  ZapIcon,
} from "@/components/icons";

export const metadata: Metadata = {
  ...createPageMetadata({
    title: "ESG e Impacto Ambiental",
    description:
      "ESG aplicado na prática: indicadores ambientais, sociais e de governança na gestão de resíduos, com rastreabilidade e relatórios para auditoria.",
    path: "/esg",
    keywords: ["ESG ambiental", "relatório ESG", "indicadores ambientais", "compensação de carbono"],
  }),
};

const impactoIndicadores = [
  { icon: UsersIcon,   value: "169,9 Mil",  label: "Pessoas impactadas" },
  { icon: LeafIcon,    value: "90,9 tCO₂e", label: "CO₂ evitado" },
  { icon: ZapIcon,     value: "118,1 Mil kWh", label: "Energia economizada" },
  { icon: SeedlingIcon,value: "645",         label: "Árvores preservadas" },
  { icon: CarIcon,     value: "53",          label: "Carros fora de circulação" },
  { icon: DropletIcon, value: "3,4 Mi",      label: "Litros de água poupada" },
];

const pilares = [
  {
    icon: LeafIcon,
    label: "Ambiental",
    items: [
      "Redução do envio ao aterro",
      "Reciclagem e valorização dos materiais",
      "Destinação ambientalmente adequada",
    ],
  },
  {
    icon: UsersIcon,
    label: "Social",
    items: [
      "Geração de renda para cooperativas",
      "Inclusão e valorização da cadeia da reciclagem",
      "Operação mais organizada e segura",
    ],
  },
  {
    icon: ShieldIcon,
    label: "Governança",
    items: [
      "Controle e supervisão da operação",
      "Rastreabilidade dos resíduos",
      "Documentação ambiental e conformidade",
    ],
  },
];

const provaTecnica = [
  {
    icon: ClipboardIcon,
    title: "PGRS",
    desc: "Plano de gerenciamento de resíduos sólidos como base do planejamento.",
  },
  {
    icon: FileTextIcon,
    title: "MTR",
    desc: "Manifesto de Transporte de Resíduos para rastrear cada movimentação.",
  },
  {
    icon: ClipboardIcon,
    title: "CDF",
    desc: "Certificado de Destinação Final comprovando o destino correto.",
  },
  {
    icon: FileTextIcon,
    title: "Relatório ESG",
    desc: "Indicadores ambientais consolidados para auditoria e divulgação.",
  },
];

export default function EsgPage() {
  return (
    <>
      <PageHero
        eyebrow="ESG e Impacto"
        title={
          <>
            ESG aplicado na{" "}
            <span className="text-(--color-accent)">prática</span>, não no
            discurso
          </>
        }
        subtitle="Transformamos gestão de resíduos em dados, rastreabilidade e impacto ambiental real."
      >
        <Button
          href="/contato"
          variant="primary"
          iconRight={<ArrowRightIcon width={18} height={18} />}
        >
          Conversar com a Ecoleta
        </Button>
      </PageHero>

      {/* Pilares */}
      <Section tone="light" id="pilares">
        <Reveal>
          <SectionHeader eyebrow="Pilares" title="Três frentes em uma só operação" />
        </Reveal>
        <ul className="grid gap-6 md:grid-cols-3">
          {pilares.map(({ icon: Icon, label, items }, i) => (
            <Reveal key={label} as="li" delay={i * 100}>
              <TiltCard intensity={4}>
                <Card tone="dark" className="h-full overflow-hidden relative">
                  <div aria-hidden className="absolute -right-10 -top-10 size-32 rounded-full bg-(--color-accent)/10" />
                  <div className="flex items-center gap-3 mb-5">
                    <span className="size-14 rounded-full bg-(--color-accent) text-(--color-bg-dark) flex items-center justify-center">
                      <Icon width={28} height={28} />
                    </span>
                    <h3 className="card-title">{label}</h3>
                  </div>
                  <ul className="space-y-3">
                    {items.map((it) => (
                      <li key={it} className="flex items-start gap-3 text-sm">
                        <span className="mt-1.5 size-1.5 rounded-full bg-(--color-accent) shrink-0" />
                        <span className="text-white/75 leading-relaxed">
                          {it}
                        </span>
                      </li>
                    ))}
                  </ul>
                </Card>
              </TiltCard>
            </Reveal>
          ))}
        </ul>
      </Section>

      {/* Indicadores de impacto */}
      <Section tone="white" id="indicadores">
        <Reveal>
          <SectionHeader
            eyebrow="Impacto mensurável"
            title="Números reais da nossa operação"
            align="center"
          />
        </Reveal>
        <ul className="grid grid-cols-2 md:grid-cols-3 gap-4 md:gap-6">
          {impactoIndicadores.map(({ icon: Icon, value, label }, i) => (
            <Reveal key={label} as="li" delay={i * 80}>
              <div className="flex flex-col items-center text-center p-6 rounded-[10px] border border-(--color-border-light) bg-(--color-bg-light) h-full">
                <span className="size-12 rounded-full bg-(--color-accent-soft) text-(--color-secondary) flex items-center justify-center mb-4">
                  <Icon width={22} height={22} />
                </span>
                <p className="text-2xl md:text-3xl font-bold text-(--color-bg-dark) leading-tight">{value}</p>
                <p className="mt-1.5 text-xs uppercase tracking-widest text-(--color-text-muted)">{label}</p>
              </div>
            </Reveal>
          ))}
        </ul>
      </Section>

      {/* Prova técnica */}
      <Section tone="dark" id="comprovacao-ambiental">
        <Reveal>
          <SectionHeader
            eyebrow="Prova técnica"
            title="Comprovação ambiental completa"
            tone="dark"
          />
        </Reveal>
        <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {provaTecnica.map(({ icon: Icon, title, desc }, i) => (
            <Reveal key={title} as="li" delay={i * 80}>
              <Card tone="dark" className="h-full">
                <div className="size-12 rounded-full bg-(--color-accent-soft) text-(--color-accent) flex items-center justify-center mb-5">
                  <Icon width={22} height={22} />
                </div>
                <h3 className="card-title mb-2">{title}</h3>
                <p className="text-sm text-white/70 leading-relaxed">{desc}</p>
              </Card>
            </Reveal>
          ))}
        </ul>
        <Reveal delay={360}>
          <p className="mt-10 text-center text-xl md:text-2xl font-bold text-(--color-accent) tracking-tight">
            Reduza custos, evite riscos e tenha controle total da sua operação
            de resíduos.
          </p>
        </Reveal>
      </Section>

      {/* Carbono */}
      <Section tone="dark" id="carbono">
        <LeafDecor position="top-right" size={280} rotate={-30} opacity={0.07} />
        <LeafDecor position="bottom-left" size={200} rotate={120} opacity={0.05} />
        <div className="grid gap-12 lg:grid-cols-[1.2fr_1fr] items-center">
          <Reveal>
            <Eyebrow>Compensação de carbono</Eyebrow>
            <h2 className="section-title mt-4">
              Sua operação gera carbono evitado — e a gente comprova
            </h2>
            <p className="mt-5 text-base text-white/80 leading-relaxed max-w-xl">
              A gestão correta dos resíduos não só reduz impacto — ela gera
              dados ambientais reais. Com rastreabilidade por fração, pesagem e
              destinação licenciada, calculamos o carbono evitado da operação
              com base em metodologias reconhecidas (GHG Protocol e DEFRA).
            </p>
            <p className="mt-6 inline-flex items-center gap-2 text-xs text-white/50 px-3 py-2 rounded-full border border-white/10">
              <SeedlingIcon width={14} height={14} />
              Baseado em metodologia reconhecida — GHG Protocol
            </p>
          </Reveal>
          <Reveal delay={150} className="flex justify-center">
            <DonutChart value={92} label="carbono evitado" size={240} />
          </Reveal>
        </div>
      </Section>

      {/* Frase de impacto */}
      <Section tone="light" id="frase-impacto" className="text-center">
        <Reveal>
          <div className="max-w-3xl mx-auto">
            <span className="inline-flex items-center justify-center size-12 rounded-full bg-(--color-accent-soft) text-(--color-secondary) mb-6">
              <RecycleIcon width={22} height={22} />
            </span>
            <p className="text-2xl md:text-4xl font-bold text-(--color-bg-dark) leading-tight tracking-tight">
              Gestão ambiental com{" "}
              <span className="text-(--color-secondary)">dados</span>,{" "}
              rastreabilidade e impacto real.
            </p>
          </div>
        </Reveal>
      </Section>

      {/* CTA */}
      <section className="bg-(--color-accent) py-16">
        <div className="container-page text-center">
          <Reveal>
            <h2 className="text-3xl md:text-4xl font-bold text-(--color-bg-dark) tracking-tight">
              Quer transformar resíduos em indicadores ESG?
            </h2>
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
