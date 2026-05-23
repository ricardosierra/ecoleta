import type { Metadata } from "next";
import { createPageMetadata } from "@/lib/seo";
import Section, { Eyebrow } from "@/components/Section";
import Card from "@/components/Card";
import Button from "@/components/Button";
import Reveal from "@/components/Reveal";
import PageHero from "@/components/PageHero";
import ContactForm from "@/components/ContactForm";
import {
  ArrowRightIcon,
  InstagramIcon,
  WhatsAppIcon,
} from "@/components/icons";
import { siteConfig, whatsappLink } from "@/lib/site.config";

export const metadata: Metadata = {
  ...createPageMetadata({
    title: "Contato",
    description:
      "Fale com a Ecoleva para estruturar a gestão de resíduos da sua empresa com rastreabilidade, conformidade ambiental e relatórios ESG.",
    path: "/contato",
    keywords: [
      "contato Ecoleva",
      "diagnóstico de resíduos",
      "consultoria ambiental",
      "conformidade ambiental",
    ],
  }),
};

export default function ContatoPage() {
  return (
    <>
      <PageHero
        eyebrow="Contato"
        title={
          <>
            Fale com a <span className="text-(--color-accent)">Ecoleva</span>
          </>
        }
        subtitle="Conte sobre sua operação e nossa equipe retornará com o melhor caminho para estruturar sua gestão de resíduos."
      />

      {/* Formulário + canais diretos */}
      <Section tone="light" id="formulario">
        <div className="grid gap-10 lg:grid-cols-[1fr_1.2fr] items-start">
          <Reveal>
            <Eyebrow className="text-(--color-secondary)">Canais diretos</Eyebrow>
            <h2 className="section-title mt-4">Resposta rápida</h2>
            <p className="mt-5 text-(--color-text-muted) leading-relaxed">
              Prefere falar agora? Use os canais abaixo para contato direto com
              o time.
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
                <ArrowRightIcon
                  className="ml-auto text-(--color-secondary)"
                  width={20}
                  height={20}
                />
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
                <ArrowRightIcon
                  className="ml-auto text-(--color-secondary)"
                  width={20}
                  height={20}
                />
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
                Falar com a Ecoleva
              </Button>
            </div>
          </Reveal>
        </div>
      </section>
    </>
  );
}
