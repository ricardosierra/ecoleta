import Link from "next/link";
import type { Metadata } from "next";
import { createBreadcrumbJsonLd, createPageMetadata } from "@/lib/seo";
import { blogPosts } from "@/lib/blog";
import PageHero from "@/components/PageHero";
import Section, { Eyebrow } from "@/components/Section";
import Card from "@/components/Card";
import Breadcrumbs from "@/components/Breadcrumbs";

export const metadata: Metadata = {
  ...createPageMetadata({
    title: "Conteúdos sobre Gestão de Resíduos",
    description:
      "Artigos da Ecoleva sobre gestão de resíduos, MTR, CDF, PGRS, ESG, eventos e economia circular.",
    path: "/conteudos",
    keywords: ["blog gestão de resíduos", "conteúdos Ecoleva", "MTR CDF", "PGRS", "ESG resíduos"],
  }),
};

export default function ConteudosPage() {
  const breadcrumbJsonLd = createBreadcrumbJsonLd([
    { name: "Home", path: "/" },
    { name: "Conteúdos", path: "/conteudos" },
  ]);

  return (
    <>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbJsonLd) }}
      />
      <PageHero
        eyebrow="Conteúdos"
        title={
          <>
            Gestão de resíduos com{" "}
            <span className="text-(--color-accent)">clareza técnica</span>
          </>
        }
        subtitle="Leituras objetivas para empresas, eventos e operações que precisam comprovar conformidade ambiental."
      />

      <Section tone="white">
        <Breadcrumbs
          items={[{ label: "Home", href: "/" }, { label: "Conteúdos" }]}
          className="mb-8"
        />
        <div className="max-w-3xl">
          <Eyebrow className="text-(--color-secondary)">Biblioteca Ecoleva</Eyebrow>
          <h2 className="section-title mt-4">Guias práticos para tomada de decisão</h2>
          <p className="mt-4 text-(--color-text-muted) leading-relaxed">
            Conteúdo público, direto e baseado em fontes oficiais para apoiar
            equipes que precisam organizar resíduos, documentação e indicadores.
          </p>
        </div>

        <div className="mt-10 grid gap-5 md:grid-cols-2">
          {blogPosts.map((post) => (
            <article key={post.slug}>
              <Link href={`/conteudos/${post.slug}`} className="block h-full">
                <Card tone="white" className="h-full border border-(--color-border-light)">
                  <div className="flex flex-wrap items-center gap-3 text-xs text-(--color-text-muted)">
                    <span className="rounded-full bg-(--color-accent-soft) px-3 py-1 font-semibold text-(--color-secondary)">
                      {post.category}
                    </span>
                    <time dateTime={post.publishedAt}>{formatDate(post.publishedAt)}</time>
                    <span>{post.readingTime}</span>
                  </div>
                  <h3 className="card-title mt-5">{post.title}</h3>
                  <p className="mt-3 text-sm leading-relaxed text-(--color-text-muted)">
                    {post.description}
                  </p>
                  <span className="mt-5 inline-block text-sm font-semibold text-(--color-secondary)">
                    Ler artigo
                  </span>
                </Card>
              </Link>
            </article>
          ))}
        </div>
      </Section>
    </>
  );
}

function formatDate(date: string) {
  return new Intl.DateTimeFormat("pt-BR", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(date));
}
