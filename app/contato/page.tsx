import type { Metadata } from "next";
import Section, { Eyebrow } from "@/components/Section";
import Card from "@/components/Card";
import Button from "@/components/Button";
import Reveal from "@/components/Reveal";
import PageHero from "@/components/PageHero";
import ContactForm from "@/components/ContactForm";
import {
  ArrowRightIcon,
  InstagramIcon,
  ShieldIcon,
  WhatsAppIcon,
} from "@/components/icons";
import { siteConfig, whatsappLink } from "@/lib/site.config";

export const metadata: Metadata = {
  title: "Sobre + Contato",
  description:
    "Especialistas em gestão de resíduos e conformidade ambiental. Fale com a equipe Ecoleta.",
};

export default function ContatoPage() {
  return (
    <>
      <PageHero
        eyebrow="Sobre + Contato"
        title={
          <>
            Especialistas em{" "}
            <span className="text-(--color-accent)">gestão de resíduos</span> e
            conformidade ambiental
          </>
        }
        subtitle="A Ecoleta estrutura operações de resíduos com foco em organização, rastreabilidade e segurança ambiental."
      />

      {/* Sobre */}
      <Section tone="white" id="sobre">
        <div className="grid gap-12 lg:grid-cols-[1.1fr_1fr] items-start">
          <Reveal>
            <Eyebrow className="text-(--color-secondary)">A empresa</Eyebrow>
            <h2 className="section-title mt-4">
              Quem é a Ecoleta
            </h2>
            <p className="mt-5 text-base md:text-lg text-(--color-text-muted) leading-relaxed">
              A Ecoleta estrutura operações de resíduos com foco em organização,
              rastreabilidade e segurança ambiental. Atendemos empresas,
              indústrias, eventos e operações complexas, com presença técnica
              direta no local.
            </p>
          </Reveal>
          <Reveal delay={120}>
            <Card tone="soft" interactive={false}>
              <div className="flex items-center gap-3 mb-4">
                <span className="size-12 rounded-full bg-(--color-accent) text-(--color-bg-dark) flex items-center justify-center">
                  <ShieldIcon width={22} height={22} />
                </span>
                <p className="text-xs uppercase tracking-widest font-semibold text-(--color-secondary)">
                  Responsável técnica
                </p>
              </div>
              <p className="text-base leading-relaxed text-(--color-text)">
                Operação conduzida por responsável técnica habilitada pelo CREA,
                garantindo conformidade com a legislação ambiental.
              </p>
            </Card>
          </Reveal>
        </div>
      </Section>

      {/* Econformidade */}
      <Section tone="dark" id="econformidade">
        <div className="grid gap-10 lg:grid-cols-[1.1fr_1fr] items-center">
          <Reveal>
            <Eyebrow>Econformidade</Eyebrow>
            <h2 className="section-title mt-4">
              Tecnologia aplicada à{" "}
              <span className="text-(--color-accent)">sustentabilidade</span>
            </h2>
            <p className="mt-5 text-base md:text-lg text-white/80 leading-relaxed max-w-xl">
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
                    className="px-5 py-4 rounded-[10px] bg-[#0a1810] border border-(--color-border-dark) text-sm font-medium"
                  >
                    {s}
                  </li>
                )
              )}
            </ul>
          </Reveal>
        </div>
      </Section>

      {/* Formulário */}
      <Section tone="light" id="formulario">
        <div className="grid gap-10 lg:grid-cols-[1fr_1.2fr] items-start">
          <Reveal>
            <Eyebrow className="text-(--color-secondary)">Contato</Eyebrow>
            <h2 className="section-title mt-4">Fale com a Ecoleta</h2>
            <p className="mt-5 text-(--color-text-muted) leading-relaxed">
              Conte sobre sua operação e nossa equipe retornará com o melhor
              caminho para estruturar sua gestão de resíduos.
            </p>

            <div className="mt-8 grid gap-3">
              <a
                href={whatsappLink}
                target="_blank"
                rel="noopener noreferrer"
                className="flex items-center gap-4 p-5 rounded-[10px] bg-white border border-(--color-border-light) hover:border-(--color-accent) hover:-translate-y-0.5 transition-all duration-200 shadow-[var(--shadow-sm)]"
              >
                <span className="size-12 rounded-full bg-[#25D366] text-white flex items-center justify-center shrink-0">
                  <WhatsAppIcon width={22} height={22} />
                </span>
                <div>
                  <p className="font-semibold text-(--color-bg-dark)">
                    Falar no WhatsApp
                  </p>
                  <p className="text-sm text-(--color-text-muted)">
                    Resposta rápida com nossa equipe
                  </p>
                </div>
                <ArrowRightIcon className="ml-auto text-(--color-secondary)" width={20} height={20} />
              </a>

              <a
                href={siteConfig.social.instagram}
                target="_blank"
                rel="noopener noreferrer"
                className="flex items-center gap-4 p-5 rounded-[10px] bg-white border border-(--color-border-light) hover:border-(--color-accent) hover:-translate-y-0.5 transition-all duration-200 shadow-[var(--shadow-sm)]"
              >
                <span className="size-12 rounded-full bg-gradient-to-br from-pink-500 via-rose-500 to-amber-400 text-white flex items-center justify-center shrink-0">
                  <InstagramIcon width={22} height={22} />
                </span>
                <div>
                  <p className="font-semibold text-(--color-bg-dark)">
                    Ver Instagram
                  </p>
                  <p className="text-sm text-(--color-text-muted)">
                    Operação real, registros e bastidores
                  </p>
                </div>
                <ArrowRightIcon className="ml-auto text-(--color-secondary)" width={20} height={20} />
              </a>
            </div>
          </Reveal>

          <Reveal delay={120}>
            <Card tone="white" interactive={false} className="lg:p-8">
              <ContactForm />
            </Card>
          </Reveal>
        </div>
      </Section>

      {/* CTA Final */}
      <section className="bg-(--color-accent) py-14">
        <div className="container-page text-center">
          <Reveal>
            <h2 className="text-2xl md:text-4xl font-bold text-(--color-bg-dark) tracking-tight">
              Menos custo, mais controle e mais impacto.
            </h2>
            <div className="mt-6">
              <Button
                href={whatsappLink}
                external
                variant="dark"
                iconRight={<ArrowRightIcon width={18} height={18} />}
              >
                Quero a Ecoleta melhorando minha gestão
              </Button>
            </div>
          </Reveal>
        </div>
      </section>
    </>
  );
}
