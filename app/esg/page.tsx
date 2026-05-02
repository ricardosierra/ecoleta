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
  LeafIcon,
  RecycleIcon,
  SeedlingIcon,
  ShieldIcon,
  UsersIcon,
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

const pilares = [
  {
    icon: LeafIcon,
    label: "Ambiental",
    items: [
      "Redução do envio ao aterro",
      "Reciclagem e valorização",
      "Destinação ambientalmente adequada",
    ],
  },
  {
    icon: UsersIcon,
    label: "Social",
    items: [
      "Geração de renda para cooperativas",
      "Inclusão social",
      "Fortalecimento da cadeia de reciclagem",
    ],
  },
  {
    icon: ShieldIcon,
    label: "Governança",
    items: [
      "Controle técnico",
      "Rastreabilidade",
      "Documentação ambiental",
    ],
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

      {/* O que é ESG */}
      <Section tone="white" id="o-que-e-esg">
        <div className="grid gap-12 lg:grid-cols-[1fr_1.2fr] items-start">
          <Reveal>
            <p className="eyebrow text-(--color-secondary) flex items-center gap-2">
              <span className="inline-block size-1.5 rounded-full bg-current" />
              O que é ESG
            </p>
            <h2 className="section-title mt-4">
              Critérios que viram operação
            </h2>
          </Reveal>
          <Reveal delay={120}>
            <p className="text-base md:text-lg text-(--color-text-muted) leading-relaxed">
              Aplicamos critérios ambientais, sociais e de governança na gestão
              de resíduos para que empresas tenham controle, documentação e
              impacto mensurável. Cada operação vira dado, cada destinação vira
              comprovação.
            </p>
          </Reveal>
        </div>
      </Section>

      {/* Pilares */}
      <Section tone="light" id="pilares">
        <Reveal>
          <SectionHeader eyebrow="Pilares" title="Três frentes em uma só operação" />
        </Reveal>
        <ul className="grid gap-6 md:grid-cols-3">
          {pilares.map(({ icon: Icon, label, items }, i) => (
            <Reveal key={label} as="li" delay={i * 100}>
              <TiltCard intensity={4}>
                <Card tone="white" className="h-full">
                  <div className="flex items-center gap-3 mb-5">
                    <span className="size-12 rounded-full bg-(--color-accent-soft) text-(--color-secondary) flex items-center justify-center">
                      <Icon width={22} height={22} />
                    </span>
                    <h3 className="card-title">{label}</h3>
                  </div>
                  <ul className="space-y-3">
                    {items.map((it) => (
                      <li key={it} className="flex items-start gap-3 text-sm">
                        <span className="mt-1.5 size-1.5 rounded-full bg-(--color-accent) shrink-0" />
                        <span className="text-(--color-text-muted) leading-relaxed">
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

      {/* Carbono */}
      <Section tone="dark" id="carbono">
        <LeafDecor position="top-right" size={280} rotate={-30} opacity={0.07} />
        <LeafDecor position="bottom-left" size={200} rotate={120} opacity={0.05} />
        <div className="grid gap-12 lg:grid-cols-[1.2fr_1fr] items-center">
          <Reveal>
            <Eyebrow>Compensação de carbono</Eyebrow>
            <h2 className="section-title mt-4">
              Compensação de carbono através da{" "}
              <span className="text-(--color-accent)">operação</span>
            </h2>
            <p className="mt-5 text-base md:text-lg text-white/80 leading-relaxed max-w-xl">
              A destinação adequada de resíduos recicláveis e orgânicos gera
              redução de emissões, permitindo compensação de carbono com base em
              dados reais.
            </p>
            <p className="mt-6 inline-flex items-center gap-2 text-xs text-white/50 px-3 py-2 rounded-full border border-white/10">
              <SeedlingIcon width={14} height={14} />
              Baseado em metodologia reconhecida — GHG Protocol
            </p>
          </Reveal>
          <Reveal delay={150} className="flex justify-center">
            <DonutChart value={68} label="desvio de aterro" size={260} />
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
