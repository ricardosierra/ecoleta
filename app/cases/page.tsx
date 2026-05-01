import type { Metadata } from "next";
import Section, { SectionHeader } from "@/components/Section";
import Card from "@/components/Card";
import Button from "@/components/Button";
import Reveal from "@/components/Reveal";
import PageHero from "@/components/PageHero";
import Typewriter from "@/components/Typewriter";
import LogoCarousel from "@/components/LogoCarousel";
import Gallery from "@/components/Gallery";
import {
  ArrowRightIcon,
  ClipboardIcon,
  FileTextIcon,
  ShieldIcon,
} from "@/components/icons";

export const metadata: Metadata = {
  title: "Cases & Provas",
  description:
    "Na prática, o que a Ecoleta entrega: operações organizadas, redução de custo, segurança jurídica e comprovação ambiental.",
};

const dynamicWords = [
  "empresas",
  "indústrias",
  "eventos",
  "operações",
  "marcas",
  "instituições",
];

const galleryItems = [
  { label: "Operação", caption: "Imagem real da operação Ecoleta" },
  { label: "Caçambas organizadas", caption: "Pontos de descarte padronizados" },
  { label: "Equipe", caption: "Supervisão técnica no local" },
  { label: "Separação de resíduos", caption: "Frações separadas corretamente" },
];

const provaTecnica = [
  {
    icon: ClipboardIcon,
    title: "PGRS",
    desc: "Plano de gerenciamento de resíduos sólidos como base do planejamento.",
  },
  {
    icon: ArrowRightIcon,
    title: "MTR",
    desc: "Manifesto de Transporte de Resíduos para rastrear cada movimentação.",
  },
  {
    icon: ShieldIcon,
    title: "CDF",
    desc: "Certificado de Destinação Final comprovando o destino correto.",
  },
  {
    icon: FileTextIcon,
    title: "Relatório ESG",
    desc: "Indicadores ambientais consolidados para auditoria e divulgação.",
  },
];

// Placeholder de clientes — substituir pelos logos reais quando disponíveis
const clientes = [
  { name: "Cliente 1" },
  { name: "Cliente 2" },
  { name: "Cliente 3" },
  { name: "Cliente 4" },
  { name: "Cliente 5" },
  { name: "Cliente 6" },
  { name: "Cliente 7" },
  { name: "Cliente 8" },
];

export default function CasesPage() {
  return (
    <>
      <PageHero
        eyebrow="Cases & Provas"
        title={
          <>
            Na prática, o que a Ecoleta entrega para{" "}
            <Typewriter
              words={dynamicWords}
              className="text-(--color-accent)"
              cursorClassName="bg-(--color-accent)"
            />
          </>
        }
        subtitle="Operações mais organizadas, redução de custo, segurança jurídica e comprovação ambiental — para empresas, indústrias e eventos."
      >
        <Button
          href="/contato"
          variant="primary"
          iconRight={<ArrowRightIcon width={18} height={18} />}
        >
          Falar com especialista
        </Button>
      </PageHero>

      {/* Case modelo */}
      <Section tone="white" id="case-modelo">
        <Reveal>
          <SectionHeader
            eyebrow="Case modelo"
            title="Como uma operação se transforma com a Ecoleta"
          />
        </Reveal>
        <div className="grid gap-5 md:grid-cols-3">
          <Reveal delay={0}>
            <Card tone="white" className="h-full border-t-4 border-rose-300">
              <p className="text-xs uppercase tracking-widest font-semibold text-rose-500 mb-3">
                Problema
              </p>
              <p className="text-base leading-relaxed text-(--color-text)">
                Desorganização, acúmulo e falta de controle nos pontos de
                descarte da operação.
              </p>
            </Card>
          </Reveal>
          <Reveal delay={120}>
            <Card tone="white" className="h-full border-t-4 border-(--color-accent)">
              <p className="text-xs uppercase tracking-widest font-semibold text-(--color-secondary) mb-3">
                Solução
              </p>
              <p className="text-base leading-relaxed text-(--color-text)">
                Gestão completa com supervisão técnica, separação por fração e
                rastreabilidade documentada.
              </p>
            </Card>
          </Reveal>
          <Reveal delay={240}>
            <Card tone="white" className="h-full border-t-4 border-(--color-secondary)">
              <p className="text-xs uppercase tracking-widest font-semibold text-(--color-secondary) mb-3">
                Resultado
              </p>
              <p className="text-base leading-relaxed text-(--color-text)">
                Operação organizada, redução de custo e conformidade ambiental
                comprovada por documentação.
              </p>
            </Card>
          </Reveal>
        </div>
      </Section>

      {/* Galeria */}
      <Section tone="light" id="galeria">
        <Reveal>
          <SectionHeader
            eyebrow="Galeria"
            title="Imagens reais da operação"
            subtitle="As imagens abaixo são placeholders. Substituir pelos registros reais da operação Ecoleta."
          />
        </Reveal>
        <Reveal delay={120}>
          <Gallery items={galleryItems} />
        </Reveal>
      </Section>

      {/* Clientes */}
      <Section tone="white" id="clientes">
        <Reveal>
          <SectionHeader
            eyebrow="Clientes atendidos"
            title="Empresas que confiam na Ecoleta"
            subtitle="Nomes ilustrativos. Os logos reais entram aqui assim que disponibilizados."
            align="center"
          />
        </Reveal>
        <Reveal delay={120}>
          <LogoCarousel items={clientes} />
        </Reveal>
      </Section>

      {/* Prova técnica */}
      <Section tone="dark" id="prova-tecnica">
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
        <Reveal delay={400}>
          <p className="mt-12 text-center text-2xl md:text-3xl font-bold text-(--color-accent) tracking-tight">
            Sem rastreabilidade, não há ESG.
          </p>
        </Reveal>
      </Section>

      {/* CTA */}
      <section className="bg-(--color-accent) py-16">
        <div className="container-page text-center">
          <Reveal>
            <h2 className="text-3xl md:text-4xl font-bold text-(--color-bg-dark) tracking-tight">
              Sua operação merece um case na Ecoleta
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
