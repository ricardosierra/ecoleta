import Section, { Eyebrow, SectionHeader } from "@/components/Section";
import LogoCarousel from "@/components/LogoCarousel";
import Card from "@/components/Card";
import Button from "@/components/Button";
import Reveal from "@/components/Reveal";
import DonutChart from "@/components/DonutChart";
import MetricCard from "@/components/MetricCard";
import Typewriter from "@/components/Typewriter";
import LetterReveal from "@/components/LetterReveal";
import LeafDecor from "@/components/LeafDecor";
import TiltCard from "@/components/TiltCard";
import HeroVideo from "@/components/HeroVideo";
import {
  AlertTriangleIcon,
  ArrowRightIcon,
  BoxesIcon,
  CheckIcon,
  ClipboardIcon,
  FileTextIcon,
  ScaleIcon,
  SettingsIcon,
  ShieldIcon,
  UsersIcon,
} from "@/components/icons";

const problems = [
  { title: "Mistura de resíduos", desc: "Frações sobrepostas comprometem o reaproveitamento e geram custo extra." },
  { title: "Falta de controle", desc: "Operação sem indicadores e sem visibilidade do que sai da empresa." },
  { title: "Acúmulo e desorganização", desc: "Caçambas mal posicionadas, pontos de descarte sem padrão." },
  { title: "Risco de multa", desc: "Resíduos sem documentação ambiental válida expõem o negócio." },
  { title: "Falta de documentação", desc: "Ausência de PGRS, MTR e CDF inviabiliza auditoria e ESG." },
];

const consultoria = [
  "Diagnóstico da operação atual",
  "Identificação de desperdícios",
  "Redução de rejeitos",
  "Otimização de processos",
  "Treinamento de equipes",
  "Direcionamento para conformidade ambiental",
];

const diferenciais = [
  {
    icon: SettingsIcon,
    title: "Gestão multiresíduos",
    desc: "Um único parceiro para toda a sua operação. Integração de fornecedores, redução de custo e mais controle.",
  },
  {
    icon: UsersIcon,
    title: "Supervisão técnica contínua",
    desc: "Operação acompanhada por especialista. Controle real da separação, limpeza e fluxo dos resíduos.",
  },
  {
    icon: BoxesIcon,
    title: "Organização operacional",
    desc: "Fluxo estruturado do início ao fim. Caçambas, pontos de descarte e logística pensados para evitar falhas.",
  },
  {
    icon: FileTextIcon,
    title: "Rastreabilidade e documentação",
    desc: "MTR, CDF, PGRS e controle completo. Tudo registrado, auditável e em conformidade com a PNRS.",
  },
  {
    icon: ClipboardIcon,
    title: "Indicadores ESG e carbono",
    desc: "Dados reais para tomada de decisão. Relatórios ESG + compensação estimada de carbono com metodologia reconhecida.",
  },
  {
    icon: ShieldIcon,
    title: "Conformidade e inovação ambiental",
    desc: "PNRS, ISO 14001 e soluções pioneiras. Gestão alinhada à legislação, incluindo atuação com resíduos têxteis.",
  },
];

const resultados = [
  { symbol: "↓R$", label: "Redução de custo operacional" },
  { symbol: "92%", label: "Menos envio ao aterro" },
  { symbol: <CheckIcon width={36} height={36} />, label: "Operação organizada e documentada" },
  { symbol: <ScaleIcon width={36} height={36} />, label: "Segurança jurídica e ambiental" },
  { symbol: "ESG", label: "Valor e reputação para sua marca" },
];

const clientes = [
  { name: "Heineken", src: "/logos/heineken.png" },
  { name: "LIESA", src: "/logos/liesa.png" },
  { name: "VIBRA", src: "/logos/vibra.png" },
  { name: "BrasilCap", src: "/logos/brasilcap.png" },
  { name: "CEDAE", src: "/logos/cedae.png" },
  { name: "Rio Carnaval", src: "/logos/rio-carnaval.png" },
  { name: "Levels", src: "/logos/levels.png" },
  { name: "Bosque Bar", src: "/logos/bosque-bar.png" },
  { name: "Ferro & Brasa", src: "/logos/ferro-e-brasa.png" },
  { name: "WeMake", src: "/logos/wemake.png" },
  { name: "Virada Sustentável", src: "/logos/virada-sustentavel.png" },
  { name: "Rio FutSummit 26", src: "/logos/rio-futsummit-26.png" },
  { name: "Sacadura 154", src: "/logos/sacadura-154.png" },
  { name: "NMLSS", src: "/logos/nmlss.png" },
  { name: "Café Preto Tattoo", src: "/logos/cafe-preto-tattoo.png" },
  { name: "FuraTodo", src: "/logos/fura-toblu.png" },
];

export default function HomePage() {
  return (
    <>
      {/* HERO */}
      <section className="relative bg-(--color-bg-dark) text-white overflow-hidden">
        {/* Vídeo de fundo */}
        <HeroVideo
          className="absolute inset-0 w-full h-full object-cover pointer-events-none"
        />
        {/* Overlay escuro para legibilidade do texto */}
        <div aria-hidden className="absolute inset-0 bg-(--color-bg-dark)/62 pointer-events-none" />
        {/* Gradiente de cor de marca */}
        <div
          aria-hidden
          className="absolute inset-0 opacity-30 pointer-events-none"
          style={{
            backgroundImage:
              "radial-gradient(circle at 20% 0%, rgba(126,217,87,0.5) 0%, transparent 40%), radial-gradient(circle at 80% 100%, rgba(126,217,87,0.3) 0%, transparent 50%)",
          }}
        />
        <div className="container-page relative pt-10 pb-16 md:pt-16 md:pb-24">
          <div className="grid gap-12 lg:grid-cols-[1.2fr_1fr] items-center">
            <Reveal>
              <span className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-(--color-accent-soft) text-(--color-accent) text-xs font-semibold tracking-wide">
                <span className="size-1.5 rounded-full bg-(--color-accent)" />
                Conformidade PNRS · ISO 14001
              </span>
              <h1 className="hero-title mt-6 text-balance">
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
                Redução máxima do envio ao aterro, organização da operação e
                segurança ambiental para empresas, eventos e indústrias.
              </p>
              <div className="mt-8 flex flex-wrap gap-3">
                <div>
                  <Button href="/contato" variant="primary" iconRight={<ArrowRightIcon width={18} height={18} />}>
                    Solicitar diagnóstico
                  </Button>
                  <p className="mt-2 text-xs text-white/50">
                    Resposta em até 3 dias úteis
                  </p>
                </div>
                <Button href="/contato" variant="ghost" className="text-white border-white/20 hover:border-(--color-accent) hover:text-(--color-accent)">
                  Falar com especialista
                </Button>
              </div>
            </Reveal>

            <Reveal delay={150}>
              <div className="relative rounded-2xl border border-white/10 bg-white/[0.03] backdrop-blur-sm p-5 md:p-8">
                <p className="text-xs uppercase tracking-widest text-(--color-accent) mb-6">
                  Indicadores da operação
                </p>
                <div className="grid grid-cols-2 gap-4">
                  <Stat value="100%" label="rastreabilidade" />
                  <Stat value="PNRS" label="conformidade" />
                  <Stat value="MTR + CDF" label="documentação" />
                  <Stat value="ESG" label="aplicado" />
                </div>
              </div>
            </Reveal>
          </div>
        </div>
      </section>

      {/* PROBLEMA */}
      <Section tone="light" id="problema">
        <Reveal>
          <SectionHeader
            eyebrow="O Problema"
            title="O problema não é o resíduo. É a falta de gestão."
          />
        </Reveal>
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          {problems.map((p, i) => (
            <Reveal key={p.title} as="li" delay={i * 80}>
              <Card tone="white" className="h-full flex flex-col">
                <div className="size-11 rounded-full bg-orange-50 text-orange-500 flex items-center justify-center mb-4">
                  <AlertTriangleIcon width={20} height={20} />
                </div>
                <h3 className="font-semibold text-base leading-snug mb-2">
                  {p.title}
                </h3>
                <p className="text-sm text-(--color-text-muted) leading-relaxed">
                  {p.desc}
                </p>
              </Card>
            </Reveal>
          ))}
        </ul>
      </Section>

      {/* CONSULTORIA */}
      <Section tone="white" id="consultoria">
        <div className="grid gap-10 lg:grid-cols-2 items-center">
          <Reveal>
            <p className="eyebrow text-(--color-secondary) flex items-center gap-2">
              <span className="inline-block size-1.5 rounded-full bg-current" />
              <LetterReveal text="Consultoria" charDelay={20} />
            </p>
            <h2 className="section-title mt-4">
              <LetterReveal
                text="Consultoria e otimização da gestão de resíduos"
                delay={11 * 20 + 100}
                charDelay={25}
              />
            </h2>
            <p className="mt-5 text-base text-(--color-text-muted) leading-relaxed">
              A Ecoleta não atua apenas na operação. Estruturamos e orientamos
              empresas para reduzir desperdícios, melhorar processos e diminuir
              custos com resíduos.
            </p>
          </Reveal>
          <Reveal delay={120}>
            <ul className="grid gap-3 sm:grid-cols-2 max-w-2xl mx-auto">
              {consultoria.map((item) => (
                <li
                  key={item}
                  className="flex items-start gap-3 p-4 rounded-[10px] bg-(--color-bg-light)"
                >
                  <span className="mt-1 size-5 rounded-full bg-(--color-accent) flex items-center justify-center shrink-0">
                    <CheckIcon
                      width={12}
                      height={12}
                      strokeWidth={3}
                      className="text-(--color-bg-dark)"
                    />
                  </span>
                  <span className="text-sm leading-snug">{item}</span>
                </li>
              ))}
            </ul>
          </Reveal>
        </div>
      </Section>

      {/* DIFERENCIAIS */}
      <Section tone="light" id="diferenciais">
        <Reveal>
          <SectionHeader
            eyebrow="Diferenciais"
            title="Mais do que coletar, garantimos conformidade."
          />
        </Reveal>
        <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {diferenciais.map(({ icon: Icon, title, desc }, i) => (
            <Reveal key={title} as="li" delay={i * 70}>
              <TiltCard intensity={5}>
                <Card tone="white" className="h-full">
                  <div className="size-12 rounded-full bg-(--color-accent-soft) text-(--color-secondary) flex items-center justify-center mb-5">
                    <Icon width={22} height={22} />
                  </div>
                  <h3 className="card-title mb-2">{title}</h3>
                  <p className="text-sm text-(--color-text-muted) leading-relaxed">
                    {desc}
                  </p>
                </Card>
              </TiltCard>
            </Reveal>
          ))}
        </ul>
      </Section>

      {/* CARBONO */}
      <Section tone="dark" id="carbono" className="overflow-hidden">
        <LeafDecor position="top-left" size={280} rotate={45} opacity={0.07} />
        <LeafDecor position="bottom-right" size={200} rotate={210} opacity={0.05} />
        <div className="grid gap-12 lg:grid-cols-[1.2fr_1fr] items-center">
          <Reveal>
            <Eyebrow>
              <LetterReveal text="Impacto de Carbono" charDelay={20} />
            </Eyebrow>
            <h2 className="section-title mt-4">
              <LetterReveal text="Sua operação gera carbono evitado — e a gente comprova" delay={18 * 20 + 100} charDelay={25} />
            </h2>
            <p className="mt-5 text-base text-white/80 leading-relaxed max-w-xl">
              A gestão correta dos resíduos não só reduz impacto — ela gera
              dados ambientais reais. Com rastreabilidade por fração, pesagem e
              destinação licenciada, calculamos o carbono evitado da operação
              com base em metodologias reconhecidas (GHG Protocol e DEFRA).
            </p>
            <p className="mt-6 text-xs text-white/40">
              Indicadores calculados a partir de dados reais da operação.
            </p>
          </Reveal>
          <Reveal delay={150} className="flex justify-center">
            <DonutChart value={92} label="desvio de aterro" size={240} />
          </Reveal>
        </div>
      </Section>

      {/* RESULTADOS */}
      <Section tone="light" id="resultados">
        <Reveal>
          <SectionHeader
            eyebrow="Resultados"
            title="Resultados que aparecem na operação e na marca"
          />
        </Reveal>
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          {resultados.map((r, i) => (
            <Reveal key={i} as="li" delay={i * 70}>
              <MetricCard symbol={r.symbol} label={r.label} className="h-full" />
            </Reveal>
          ))}
        </ul>
      </Section>

      {/* CLIENTES */}
      <Section tone="white" id="clientes">
        <Reveal>
          <SectionHeader
            eyebrow="Clientes atendidos"
            title="Empresas que confiam na Ecoleta"
            subtitle="Da produtora de eventos à empresa pública — gestão de resíduos que se adapta a qualquer operação."
            align="center"
          />
        </Reveal>
        <Reveal delay={120}>
          <LogoCarousel items={clientes} />
        </Reveal>
      </Section>

      {/* CTA FINAL */}
      <section className="bg-(--color-accent) py-16 md:py-20">
        <div className="container-page text-center">
          <Reveal>
            <h2 className="text-2xl md:text-4xl font-bold text-(--color-bg-dark) leading-tight tracking-tight">
              <LetterReveal text="Transformamos resíduos em reputação." charDelay={30} />
            </h2>
            <p className="mt-4 text-(--color-bg-dark)/80 text-base max-w-3xl mx-auto">
              Soluções em gestão de resíduos com rastreabilidade, supervisão
              técnica e conformidade ambiental para operações mais organizadas,
              seguras e sustentáveis.
            </p>
            <div className="mt-8 flex flex-wrap gap-3 justify-center">
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

function Stat({ value, label }: { value: string; label: string }) {
  return (
    <div className="rounded-[10px] bg-(--color-bg-dark) border border-(--color-border-dark) p-3 md:p-5">
      <p className="text-lg md:text-[1.75rem] font-bold text-(--color-accent) leading-tight break-words min-w-0">
        {value}
      </p>
      <p className="text-[0.65rem] md:text-xs uppercase tracking-widest text-white/50 mt-1.5 break-words min-w-0">
        {label}
      </p>
    </div>
  );
}
