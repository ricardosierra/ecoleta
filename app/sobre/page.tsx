import type { Metadata } from "next";
import { createPageMetadata } from "@/lib/seo";
import Section, { Eyebrow, SectionHeader } from "@/components/Section";
import Card from "@/components/Card";
import Button from "@/components/Button";
import Reveal from "@/components/Reveal";
import PageHero from "@/components/PageHero";
import {
  ArrowRightIcon,
  ShieldIcon,
} from "@/components/icons";

export const metadata: Metadata = {
  ...createPageMetadata({
    title: "Sobre a Ecoleta",
    description:
      "Quem é a Ecoleta — empresa de gestão de resíduos com presença técnica direta, rastreabilidade e foco em conformidade ambiental e ESG.",
    path: "/sobre",
    keywords: [
      "sobre Ecoleta",
      "empresa gestão de resíduos",
      "Econformidade",
      "responsável técnica ambiental",
    ],
  }),
};

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
        subtitle="A Ecoleta estrutura operações de resíduos com foco em organização, rastreabilidade e segurança ambiental."
      />

      {/* Quem é a Ecoleta */}
      <Section tone="white" id="quem-somos">
        <div className="grid gap-12 lg:grid-cols-[1.1fr_1fr] items-start">
          <Reveal>
            <Eyebrow className="text-(--color-secondary)">A empresa</Eyebrow>
            <h2 className="section-title mt-4">Quem é a Ecoleta</h2>
            <p className="mt-5 text-base md:text-lg text-(--color-text-muted) leading-relaxed">
              A Ecoleta estrutura operações de resíduos com foco em organização,
              rastreabilidade e segurança ambiental. Atendemos empresas,
              indústrias, eventos e operações complexas, com presença técnica
              direta no local.
            </p>
            <p className="mt-4 text-base md:text-lg text-(--color-text-muted) leading-relaxed">
              Transformamos resíduos em reputação — entregando não só destinação
              correta, mas também os indicadores e a documentação que comprovam
              o impacto positivo da operação.
            </p>
          </Reveal>
          <Reveal delay={120}>
            <div className="relative aspect-[4/5] rounded-[10px] overflow-hidden border border-(--color-border-light) bg-(--color-bg-light)">
              <div
                className="absolute inset-0"
                style={{
                  backgroundImage:
                    "radial-gradient(circle at 30% 20%, rgba(126,217,87,0.18) 0%, transparent 60%), radial-gradient(circle at 80% 80%, rgba(45,89,52,0.14) 0%, transparent 60%)",
                }}
              />
              <div className="absolute top-3 right-3 px-2.5 py-1 rounded-full bg-white/90 text-[10px] uppercase tracking-widest text-(--color-secondary) font-semibold">
                placeholder
              </div>
              <div className="absolute inset-0 flex items-center justify-center text-xs uppercase tracking-widest text-(--color-secondary) font-semibold">
                Foto da equipe
              </div>
            </div>
          </Reveal>
        </div>
      </Section>

      {/* Posicionamento */}
      <Section tone="dark" id="posicionamento">
        <Reveal>
          <SectionHeader
            eyebrow="Posicionamento"
            title="Mais do que coletar — comprovar."
            tone="dark"
          />
        </Reveal>
        <ul className="grid gap-6 md:grid-cols-3">
          {[
            {
              title: "Operação técnica",
              desc: "Supervisão presencial, organização de pontos de descarte e separação por fração — controle real, não promessa.",
            },
            {
              title: "Documentação completa",
              desc: "PGRS, MTR e CDF emitidos para cada movimentação. Tudo rastreável, tudo auditável.",
            },
            {
              title: "Indicadores ESG",
              desc: "Resultados consolidados em relatórios para divulgação, conformidade e tomada de decisão.",
            },
          ].map((item, i) => (
            <Reveal key={item.title} as="li" delay={i * 100}>
              <Card tone="dark" className="h-full border-l-4 border-(--color-accent)">
                <h3 className="card-title mb-3">{item.title}</h3>
                <p className="text-sm text-white/70 leading-relaxed">
                  {item.desc}
                </p>
              </Card>
            </Reveal>
          ))}
        </ul>
      </Section>

      {/* Responsável técnica */}
      <Section tone="light" id="responsavel-tecnica">
        <div className="grid gap-12 lg:grid-cols-[1fr_1.1fr] items-center">
          <Reveal>
            <Eyebrow className="text-(--color-secondary)">Conformidade</Eyebrow>
            <h2 className="section-title mt-4">
              Responsável técnica habilitada
            </h2>
            <p className="mt-5 text-base md:text-lg text-(--color-text-muted) leading-relaxed">
              Operação conduzida por responsável técnica habilitada pelo CREA,
              garantindo conformidade com a PNRS, padrões ISO 14001 e a
              legislação ambiental aplicável.
            </p>
          </Reveal>
          <Reveal delay={120}>
            <Card tone="white" interactive={false}>
              <div className="flex items-center gap-3 mb-4">
                <span className="size-12 rounded-full bg-(--color-accent) text-(--color-bg-dark) flex items-center justify-center">
                  <ShieldIcon width={22} height={22} />
                </span>
                <p className="text-xs uppercase tracking-widest font-semibold text-(--color-secondary)">
                  CREA · ISO 14001 · PNRS
                </p>
              </div>
              <p className="text-base leading-relaxed text-(--color-text)">
                Toda a operação Ecoleta é executada com supervisão técnica,
                dentro dos padrões exigidos para auditoria e conformidade
                ambiental.
              </p>
            </Card>
          </Reveal>
        </div>
      </Section>

      {/* Econformidade */}
      <Section tone="white" id="econformidade">
        <div className="grid gap-10 lg:grid-cols-[1.1fr_1fr] items-center">
          <Reveal>
            <Eyebrow className="text-(--color-secondary)">Econformidade</Eyebrow>
            <h2 className="section-title mt-4">
              Tecnologia aplicada à{" "}
              <span className="text-(--color-accent)">sustentabilidade</span>
            </h2>
            <p className="mt-5 text-base md:text-lg text-(--color-text-muted) leading-relaxed max-w-xl">
              A Econformidade é o braço tecnológico da Ecoleta, responsável por
              automação, indicadores ESG e estruturação de dados ambientais.
            </p>
          </Reveal>
          <Reveal delay={120}>
            <ul className="grid gap-3 sm:grid-cols-2">
              {["Automação", "Dashboards", "Sistemas de controle", "Treinamentos"].map(
                (s) => (
                  <li
                    key={s}
                    className="px-5 py-4 rounded-[10px] bg-(--color-bg-light) border border-(--color-border-light) text-sm font-medium text-(--color-text)"
                  >
                    {s}
                  </li>
                )
              )}
            </ul>
          </Reveal>
        </div>
      </Section>

      {/* CTA */}
      <section className="bg-(--color-accent) py-16">
        <div className="container-page text-center">
          <Reveal>
            <h2 className="text-3xl md:text-4xl font-bold text-(--color-bg-dark) tracking-tight">
              Vamos estruturar a sua operação?
            </h2>
            <p className="mt-3 text-(--color-bg-dark)/80">
              Solicite um diagnóstico e veja o que a Ecoleta pode entregar.
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
