import type { Metadata } from "next";
import Image from "next/image";
import { createPageMetadata } from "@/lib/seo";
import Section, { Eyebrow, SectionHeader } from "@/components/Section";
import Card from "@/components/Card";
import Button from "@/components/Button";
import Reveal from "@/components/Reveal";
import PageHero from "@/components/PageHero";
import Gallery from "@/components/Gallery";
import {
  ArrowRightIcon,
} from "@/components/icons";

export const metadata: Metadata = {
  ...createPageMetadata({
    title: "Sobre a Ecoleva",
    description:
      "Quem é a Ecoleva, empresa de gestão de resíduos com presença técnica direta, rastreabilidade e foco em conformidade ambiental e ESG.",
    path: "/sobre",
    keywords: [
      "sobre Ecoleva",
      "empresa gestão de resíduos",
      "Econformidade",
      "responsável técnica ambiental",
    ],
  }),
};

const caseModelo = [
  {
    title: "Problema",
    tone: "text-rose-500",
    border: "border-rose-300",
    desc: "Desorganização, acúmulo e falta de controle nos pontos de descarte da operação.",
  },
  {
    title: "Solução",
    tone: "text-(--color-secondary)",
    border: "border-(--color-accent)",
    desc: "Gestão completa com supervisão técnica, separação por fração e rastreabilidade documentada.",
  },
  {
    title: "Resultado",
    tone: "text-(--color-secondary)",
    border: "border-(--color-secondary)",
    desc: "Operação organizada, redução de custo e conformidade ambiental comprovada por documentação.",
  },
];

const galleryItems = [
  {
    src: "/gallery/caminhao-cacamba-operacao.jpg",
    alt: "Caminhão caçamba da Ecoleta em operação com técnico uniformizado",
  },
  {
    src: "/gallery/supervisao-coleta-01.jpg",
    alt: "Supervisão técnica da coleta em campo",
  },
  {
    src: "/gallery/carga-reciclaveis.jpg",
    alt: "Carregamento de recicláveis para destinação",
  },
  {
    src: "/gallery/operacao-triagem-noturna.jpg",
    alt: "Equipe Ecoleva fazendo triagem noturna de resíduos",
  },
  {
    src: "/gallery/triagem-reciclaveis-dia.jpg",
    alt: "Equipe Ecoleva triando recicláveis durante operação em campo",
  },
  {
    src: "/gallery/pontos-coleta-sustentavel.jpg",
    alt: "Pontos de coleta seletiva sinalizados em evento sustentável",
  },
];

export default function SobrePage() {
  return (
    <>
      <PageHero
        eyebrow="Sobre"
        title={
          <>
            Especialistas em{" "}
            <span className="text-(--color-accent)">gestão de resíduos</span> e
            conformidade ambiental
          </>
        }
        subtitle="A Ecoleva estrutura operações de resíduos com foco em organização, rastreabilidade e segurança ambiental."
      />

      {/* Quem é a Ecoleva */}
      <Section tone="white" id="quem-somos">
        <div className="grid gap-10 lg:grid-cols-[1.05fr_1fr] items-center">
          <Reveal>
            <Eyebrow className="text-(--color-secondary)">A empresa</Eyebrow>
            <h2 className="section-title mt-4">Quem é a Ecoleva</h2>
            <p className="mt-5 text-base text-(--color-text-muted) leading-relaxed">
              A Ecoleva estrutura operações de resíduos com foco em organização,
              rastreabilidade e segurança ambiental. Atendemos empresas,
              indústrias, eventos e operações complexas, com presença técnica
              direta no local.
            </p>
            <p className="mt-4 text-base text-(--color-text-muted) leading-relaxed">
              Transformamos resíduos em reputação, entregando não só destinação
              correta, mas também os indicadores e a documentação que comprovam
              o impacto positivo da operação.
            </p>
          </Reveal>
          <Reveal delay={120}>
            <div className="relative aspect-[4/3] rounded-[10px] overflow-hidden border border-(--color-border-light) bg-(--color-bg-light)">
              <Image
                src="/gallery/equipe-ecoleta-sambadrome.jpg"
                alt="Equipe Ecoleta em operação no sambódromo, com camisas da Econformidade e da gestão de resíduos"
                fill
                sizes="(max-width: 1024px) 100vw, 45vw"
                className="object-cover"
              />
            </div>
          </Reveal>
        </div>
      </Section>

      {/* Case modelo */}
      <Section tone="light" id="case-modelo">
        <Reveal>
          <SectionHeader
            eyebrow="Case modelo"
            title="Como uma operação se transforma com a Ecoleva"
          />
        </Reveal>
        <div className="grid gap-5 md:grid-cols-3">
          {caseModelo.map((item, i) => (
            <Reveal key={item.title} delay={i * 120}>
              <Card tone="white" className={`h-full border-t-4 ${item.border}`}>
                <p className={`text-sm uppercase tracking-widest font-bold mb-3 ${item.tone}`}>
                  {item.title}
                </p>
                <p className="text-base leading-relaxed text-(--color-text)">
                  {item.desc}
                </p>
              </Card>
            </Reveal>
          ))}
        </div>
      </Section>

      {/* Galeria */}
      <Section tone="white" id="galeria">
        <Reveal>
          <SectionHeader
            eyebrow="Galeria"
            title="Imagens reais da operação"
          />
        </Reveal>
        <Reveal delay={120}>
          <Gallery items={galleryItems} />
        </Reveal>
      </Section>

      {/* Econformidade */}
      <Section tone="light" id="econformidade">
        <div className="grid gap-10 lg:grid-cols-[0.9fr_1.2fr] items-center">
          <Reveal>
            <Eyebrow className="!text-base md:!text-lg font-semibold text-(--color-secondary)">
              Econformidade
            </Eyebrow>
            <h2 className="section-title mt-4">
              Tecnologia aplicada à{" "}
              <span className="text-(--color-accent)">sustentabilidade</span>
            </h2>
            <p className="mt-5 text-base text-(--color-text-muted) leading-relaxed max-w-xl">
              A{" "}
              <strong className="font-bold text-(--color-bg-dark)">
                Econformidade
              </strong>{" "}
              é o braço tecnológico da Ecoleva, responsável por automação,
              indicadores ESG e estruturação de dados ambientais.
            </p>
            <ul className="mt-6 grid gap-3 sm:grid-cols-2">
              {["Automação", "Dashboards", "Sistemas de controle", "Treinamentos"].map(
                (s) => (
                  <li
                    key={s}
                    className="px-4 py-3 rounded-[10px] bg-white border border-(--color-border-light) text-sm font-medium text-(--color-text)"
                  >
                    {s}
                  </li>
                )
              )}
            </ul>
          </Reveal>
          <Reveal delay={120}>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="relative sm:col-span-2 aspect-[16/9] rounded-[10px] overflow-hidden border border-(--color-border-light) bg-white">
                <Image
                  src="/gallery/dashboard-indicadores.png"
                  alt="Dashboard de indicadores ambientais da Ecoleva"
                  fill
                  sizes="(max-width: 1024px) 100vw, 55vw"
                  className="object-cover"
                />
              </div>
              <div className="relative aspect-[4/3] rounded-[10px] overflow-hidden border border-(--color-border-light) bg-white">
                <Image
                  src="/gallery/treinamento-equipe-01.jpg"
                  alt="Treinamento operacional da equipe Ecoleva"
                  fill
                  sizes="(max-width: 640px) 100vw, 28vw"
                  className="object-cover"
                />
              </div>
              <div className="relative aspect-[4/3] rounded-[10px] overflow-hidden border border-(--color-border-light) bg-white">
                <Image
                  src="/gallery/treinamento-equipe-02.jpg"
                  alt="Treinamento com equipe em campo"
                  fill
                  sizes="(max-width: 640px) 100vw, 28vw"
                  className="object-cover"
                />
              </div>
            </div>
          </Reveal>
        </div>
      </Section>

      {/* CTA */}
      <section className="bg-(--color-accent) py-14">
        <div className="container-page text-center">
          <Reveal>
            <h2 className="text-2xl md:text-4xl font-bold text-(--color-bg-dark) tracking-tight">
              Vamos estruturar a sua operação?
            </h2>
            <p className="mt-3 text-(--color-bg-dark)/80">
              Solicite um diagnóstico e veja o que a Ecoleva pode entregar.
            </p>
            <div className="mt-7">
              <Button
                href="/contato"
                variant="dark"
                iconRight={<ArrowRightIcon width={18} height={18} />}
              >
                Falar com especialista
              </Button>
            </div>
          </Reveal>
        </div>
      </section>
    </>
  );
}
