import type { Metadata } from "next";
import { createPageMetadata } from "@/lib/seo";
import { siteConfig } from "@/lib/site.config";
import Section, { SectionHeader } from "@/components/Section";
import Card from "@/components/Card";
import Button from "@/components/Button";
import Reveal from "@/components/Reveal";
import ProcessSteps from "@/components/ProcessSteps";
import TiltCard from "@/components/TiltCard";
import Typewriter from "@/components/Typewriter";
import LetterReveal from "@/components/LetterReveal";
import LeafDecor from "@/components/LeafDecor";
import FractionsRotator, { type Fraction } from "@/components/FractionsRotator";
import {
  AlertTriangleIcon,
  ArrowRightIcon,
  BoxesIcon,
  ClipboardIcon,
  LeafIcon,
  RecycleIcon,
  SeedlingIcon,
  SettingsIcon,
  ShieldIcon,
  ShuffleIcon,
} from "@/components/icons";

export const metadata: Metadata = {
  ...createPageMetadata({
    title: "Soluções em Gestão de Resíduos",
    description:
      "Gestão completa de resíduos, do planejamento à comprovação. Diagnóstico, PGRS, supervisão técnica, MTR, CDF e relatório ESG para empresas.",
    path: "/solucoes",
    keywords: ["gestão de resíduos para empresas", "PGRS", "destinação de resíduos", "supervisão técnica ambiental"],
  }),
};

const steps = [
  { title: "Diagnóstico", desc: "Levantamento da operação e dos resíduos gerados" },
  { title: "Planejamento", desc: "Elaboração do PGRS e estruturação dos pontos" },
  { title: "Implementação", desc: "Instalação, sinalizações, treinamento e configuração da operação" },
  { title: "Operação", desc: "Supervisão técnica e controle contínuo" },
  { title: "Destinação", desc: "Envio correto por fração com rastreabilidade" },
  { title: "Relatórios", desc: "Indicadores e documentação para auditoria" },
];

const pilares = [
  {
    icon: SettingsIcon,
    title: "Gestão operacional",
    desc: "Estruturação completa da operação de resíduos com supervisão técnica, organização logística e controle operacional.",
    items: [
      "Planejamento e implantação da operação",
      "Supervisão técnica presencial",
      "Organização das coletas e descarte ambientalmente corretos",
      "Sinalização ambiental do local",
      "Segregação por tipo de resíduo",
      "Controle de caçambas e armazenamento em big bags",
    ],
  },
  {
    icon: LeafIcon,
    title: "ESG aplicado",
    desc: "Indicadores ambientais e rastreabilidade para transformar a gestão de resíduos em dados, conformidade e valor para a marca.",
    items: [
      "Indicadores ambientais da operação",
      "Redução do envio de resíduos ao aterro",
      "Compensação de carbono",
      "Relatórios ESG estruturados",
      "Dados para patrocinadores e auditorias",
      "Alinhado à PNRS e ISO 14001",
    ],
  },
  {
    icon: ClipboardIcon,
    title: "Conformidade e rastreabilidade",
    desc: "Documentação ambiental e controle operacional para garantir conformidade, segurança jurídica e rastreabilidade da destinação dos resíduos.",
    items: [
      "PGRS",
      "Emissão e controle de MTR",
      "Certificado de Destinação Final (CDF)",
      "Controle de pesagens por fração",
      "Rastreabilidade da destinação",
      "Controle ambiental da operação",
    ],
  },
];

const fractions: Fraction[] = [
  {
    name: "Recicláveis",
    tag: "Papel • Plástico • Metal • Vidro",
    desc: "Triagem e reciclagem.",
    icon: <RecycleIcon width={28} height={28} />,
    bulletClass: "bg-(--color-accent)",
    accentClass: "text-(--color-accent)",
  },
  {
    name: "Orgânicos",
    tag: "Restos alimentares e resíduos orgânicos",
    desc: "Compostagem.",
    icon: <SeedlingIcon width={28} height={28} />,
    bulletClass: "bg-emerald-300",
    accentClass: "text-emerald-300",
  },
  {
    name: "Rejeitos",
    tag: "Resíduos sem viabilidade de reciclagem",
    desc: "Destinação ambientalmente adequada.",
    icon: <ShuffleIcon width={28} height={28} />,
    bulletClass: "bg-zinc-400",
    accentClass: "text-zinc-300",
  },
  {
    name: "Infectantes",
    tag: "Materiais contaminados e biológicos",
    desc: "Tratamento e incineração licenciada.",
    icon: <ShieldIcon width={28} height={28} />,
    bulletClass: "bg-rose-400",
    accentClass: "text-rose-400",
  },
  {
    name: "Têxteis",
    tag: "Uniformes, tecidos e resíduos têxteis",
    desc: "Reuso, upcycling e reciclagem.",
    icon: <BoxesIcon width={28} height={28} />,
    bulletClass: "bg-amber-400",
    accentClass: "text-amber-400",
  },
  {
    name: "Perigosos",
    tag: "Químicos, contaminados e Classe I",
    desc: "Tratamento e incineração industrial.",
    icon: <AlertTriangleIcon width={28} height={28} />,
    bulletClass: "bg-orange-500",
    accentClass: "text-orange-400",
    badge: "Classe I · Perigoso",
  },
];

export default function SolucoesPage() {
  const serviceJsonLd = {
    "@context": "https://schema.org",
    "@type": "Service",
    name: "Gestão completa de resíduos",
    serviceType: "Gestão de resíduos, documentação ambiental e rastreabilidade",
    provider: {
      "@type": "Organization",
      name: siteConfig.name,
      url: siteConfig.url,
    },
    areaServed: {
      "@type": "AdministrativeArea",
      name: "Estado do Rio de Janeiro",
    },
    audience: {
      "@type": "BusinessAudience",
      audienceType: "Empresas, eventos, indústrias e marcas",
    },
    hasOfferCatalog: {
      "@type": "OfferCatalog",
      name: "Serviços ambientais Ecoleta",
      itemListElement: [
        "Diagnóstico e PGRS",
        "Supervisão técnica em campo",
        "Coleta e destinação multiresíduos",
        "MTR, CDF e relatório ESG",
        "Treinamento e capacitação de equipes",
      ].map((name) => ({
        "@type": "Offer",
        itemOffered: {
          "@type": "Service",
          name,
        },
      })),
    },
  };

  return (
    <>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(serviceJsonLd) }}
      />
      {/* HERO com typewriter + indicadores */}
      <section className="relative bg-(--color-bg-dark) text-white overflow-hidden">
        <div
          aria-hidden
          className="absolute inset-0 opacity-[0.07] pointer-events-none"
          style={{
            backgroundImage:
              "radial-gradient(circle at 20% 0%, rgba(126,217,87,0.6) 0%, transparent 40%), radial-gradient(circle at 80% 100%, rgba(126,217,87,0.4) 0%, transparent 50%)",
          }}
        />
        <LeafDecor position="top-right" size={320} rotate={-25} opacity={0.08} />
        <LeafDecor position="bottom-left" size={260} rotate={150} opacity={0.06} />
        <div className="container-page relative pt-12 pb-20 md:pt-20 md:pb-28">
          <div className="grid gap-12 lg:grid-cols-[1.2fr_1fr] items-center">
            <Reveal>
              <p className="eyebrow flex items-center gap-2 mb-5">
                <span className="inline-block size-1.5 rounded-full bg-current" />
                Soluções
              </p>
              <h1 className="hero-title text-balance">
                <LetterReveal text="Gestão de resíduos com " charDelay={35} />
                <Typewriter
                  className="text-(--color-accent)"
                  cursorClassName="bg-(--color-accent)"
                  words={[
                    "rastreabilidade",
                    "conformidade",
                    "controle real",
                    "impacto ESG",
                  ]}
                />
                <br className="hidden sm:inline" />
                <LetterReveal text="e operação sob controle" delay={200} charDelay={35} />
              </h1>
              <p className="mt-6 text-base md:text-lg text-white/80 max-w-2xl leading-relaxed">
                A Ecoleta estrutura, acompanha e comprova cada etapa da
                operação, reduzindo riscos e aumentando o controle ambiental.
              </p>
              <div className="mt-8 flex flex-wrap gap-3">
                <div>
                  <Button
                    href="/contato"
                    variant="primary"
                    iconRight={<ArrowRightIcon width={18} height={18} />}
                  >
                    Solicitar diagnóstico
                  </Button>
                  <p className="mt-2 text-xs text-white/50">
                    Resposta em até 3 dias úteis
                  </p>
                </div>
                <Button
                  href="/contato"
                  variant="ghost"
                  className="text-white border-white/20 hover:border-(--color-accent) hover:text-(--color-accent)"
                >
                  Falar com especialista
                </Button>
              </div>
            </Reveal>

            <Reveal delay={150}>
              <div className="relative rounded-2xl border border-white/10 bg-white/[0.03] backdrop-blur-sm p-8">
                <p className="text-xs uppercase tracking-widest text-(--color-accent) mb-6">
                  Indicadores da operação
                </p>
                <ul className="grid grid-cols-2 gap-4">
                  <HeroStat value="+200" label="toneladas gerenciadas" />
                  <HeroStat value="+100" label="operações realizadas" />
                  <HeroStat value="100%" label="rastreabilidade documental" />
                  <HeroStat value="92%" label="redução de envio ao aterro" />
                </ul>
              </div>
            </Reveal>
          </div>
        </div>
      </section>

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
            align="center"
          />
        </Reveal>
        <ul className="grid gap-6 md:grid-cols-3">
          {pilares.map(({ icon: Icon, title, desc, items }, i) => (
            <Reveal key={title} as="li" delay={i * 100}>
              <TiltCard intensity={4}>
                <Card tone="white" className="h-full text-center flex flex-col items-center">
                  <div className="size-16 rounded-full bg-(--color-accent-soft) text-(--color-secondary) flex items-center justify-center mb-6">
                    <Icon width={28} height={28} />
                  </div>
                  <h3 className="card-title mb-3">{title}</h3>
                  <p className="text-sm text-(--color-text-muted) leading-relaxed mb-5">
                    {desc}
                  </p>
                  <ul className="space-y-2 text-left w-full max-w-[17rem]">
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
        <Reveal delay={120}>
          <FractionsRotator fractions={fractions} />
        </Reveal>
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
                Supervisão técnica contínua com controle da operação,
                rastreabilidade e gestão por fração, reduzindo riscos
                operacionais, evitando passivos ambientais e garantindo
                conformidade com a PNRS e padrões ISO 14001.
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
              <StatCard value="92%" label="desvio de aterro" />
              <StatCard value="96%" label="carbono compensado" />
              <StatCard value="100%" label="destinação ambientalmente adequada" />
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

function HeroStat({ value, label }: { value: string; label: string }) {
  return (
    <li className="rounded-[10px] bg-(--color-bg-dark) border border-(--color-border-dark) p-5">
      <p className="text-2xl md:text-[1.75rem] font-bold text-(--color-accent) leading-tight">
        {value}
      </p>
      <p className="text-xs uppercase tracking-widest text-white/50 mt-2">
        {label}
      </p>
    </li>
  );
}
