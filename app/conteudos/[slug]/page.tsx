import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { blogPosts, getBlogPost } from "@/lib/blog";
import { createBreadcrumbJsonLd, createPageMetadata } from "@/lib/seo";
import { siteConfig } from "@/lib/site.config";
import Breadcrumbs from "@/components/Breadcrumbs";
import Button from "@/components/Button";
import Section from "@/components/Section";

type Props = {
  params: Promise<{ slug: string }>;
};

export function generateStaticParams() {
  return blogPosts.map((post) => ({ slug: post.slug }));
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const post = getBlogPost(slug);

  if (!post) return {};

  const metadata = createPageMetadata({
    title: post.title,
    description: post.description,
    path: `/conteudos/${post.slug}`,
    keywords: post.keywords,
  });

  return {
    ...metadata,
    openGraph: {
      type: "article",
      locale: siteConfig.locale,
      url: `${siteConfig.url.replace(/\/$/, "")}/conteudos/${post.slug}`,
      siteName: siteConfig.name,
      title: `${post.title} | ${siteConfig.name}`,
      description: post.description,
      publishedTime: post.publishedAt,
      modifiedTime: post.updatedAt,
      authors: [siteConfig.name],
      tags: post.keywords,
      images: [
        {
          url: "/og-image.png",
          width: 1200,
          height: 630,
          alt: `${post.title} | ${siteConfig.name}`,
        },
      ],
    },
  };
}

export default async function ConteudoPostPage({ params }: Props) {
  const { slug } = await params;
  const post = getBlogPost(slug);

  if (!post) notFound();

  const postUrl = `${siteConfig.url.replace(/\/$/, "")}/conteudos/${post.slug}`;
  const relatedPosts = blogPosts
    .filter((item) => item.slug !== post.slug)
    .slice(0, 3);
  const breadcrumbJsonLd = createBreadcrumbJsonLd([
    { name: "Home", path: "/" },
    { name: "Conteúdos", path: "/conteudos" },
    { name: post.title, path: `/conteudos/${post.slug}` },
  ]);
  const articleJsonLd = {
    "@context": "https://schema.org",
    "@type": "BlogPosting",
    headline: post.title,
    description: post.description,
    datePublished: post.publishedAt,
    dateModified: post.updatedAt,
    inLanguage: "pt-BR",
    articleSection: post.category,
    keywords: post.keywords,
    mainEntityOfPage: {
      "@type": "WebPage",
      "@id": postUrl,
    },
    author: {
      "@type": "Organization",
      name: siteConfig.name,
      url: siteConfig.url,
    },
    publisher: {
      "@type": "Organization",
      name: siteConfig.name,
      logo: {
        "@type": "ImageObject",
        url: `${siteConfig.url.replace(/\/$/, "")}/icon-512.png`,
      },
    },
  };

  return (
    <>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(articleJsonLd) }}
      />
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbJsonLd) }}
      />
      <article>
        <Section tone="dark" className="pt-10 md:pt-16">
          <div className="max-w-3xl">
            <Breadcrumbs
              tone="dark"
              items={[
                { label: "Home", href: "/" },
                { label: "Conteúdos", href: "/conteudos" },
                { label: post.title },
              ]}
              className="mb-8"
            />
            <div className="flex flex-wrap items-center gap-3 text-xs text-white/60">
              <span className="rounded-full bg-(--color-accent-soft) px-3 py-1 font-semibold text-(--color-accent)">
                {post.category}
              </span>
              <time dateTime={post.publishedAt}>{formatDate(post.publishedAt)}</time>
              <span>{post.readingTime}</span>
            </div>
            <h1 className="hero-title mt-6 text-balance">{post.title}</h1>
            <p className="mt-6 text-base md:text-lg leading-relaxed text-white/80">
              {post.description}
            </p>
          </div>
        </Section>

        <Section tone="white">
          <div className="grid gap-10 lg:grid-cols-[minmax(0,1fr)_280px] lg:items-start">
            <div className="max-w-3xl">
              <p className="text-lg leading-relaxed text-(--color-text-muted)">
                {post.intro}
              </p>

              <div className="mt-8 rounded-[10px] border border-(--color-border-light) bg-(--color-bg-light) p-6">
                <h2 className="text-lg font-semibold text-(--color-bg-dark)">
                  Pontos principais
                </h2>
                <ul className="mt-4 space-y-3">
                  {post.takeaways.map((item) => (
                    <li key={item} className="flex gap-3 text-sm leading-relaxed">
                      <span className="mt-2 size-1.5 shrink-0 rounded-full bg-(--color-accent)" />
                      <span>{item}</span>
                    </li>
                  ))}
                </ul>
              </div>

              <div className="mt-10 space-y-10">
                {post.sections.map((section) => (
                  <section key={section.heading}>
                    <h2 className="text-2xl font-bold leading-tight text-(--color-bg-dark)">
                      {section.heading}
                    </h2>
                    <div className="mt-4 space-y-4">
                      {section.body.map((paragraph) => (
                        <p
                          key={paragraph}
                          className="leading-relaxed text-(--color-text-muted)"
                        >
                          {paragraph}
                        </p>
                      ))}
                    </div>
                  </section>
                ))}
              </div>

              <section className="mt-12 border-t border-(--color-border-light) pt-8">
                <h2 className="text-lg font-semibold text-(--color-bg-dark)">
                  Fontes consultadas
                </h2>
                <ul className="mt-4 space-y-2">
                  {post.sources.map((source) => (
                    <li key={source.url}>
                      <a
                        href={source.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-sm font-medium text-(--color-secondary) underline-offset-4 hover:underline"
                      >
                        {source.label}
                      </a>
                    </li>
                  ))}
                </ul>
              </section>

              <section className="mt-12 border-t border-(--color-border-light) pt-8">
                <h2 className="text-lg font-semibold text-(--color-bg-dark)">
                  Continue lendo
                </h2>
                <ul className="mt-4 grid gap-3">
                  {relatedPosts.map((item) => (
                    <li key={item.slug}>
                      <a
                        href={`/conteudos/${item.slug}`}
                        className="block rounded-[10px] border border-(--color-border-light) p-4 transition-colors hover:border-(--color-accent)"
                      >
                        <span className="text-xs font-semibold uppercase tracking-[0.16em] text-(--color-secondary)">
                          {item.category}
                        </span>
                        <span className="mt-1 block font-semibold text-(--color-bg-dark)">
                          {item.title}
                        </span>
                      </a>
                    </li>
                  ))}
                </ul>
              </section>
            </div>

            <aside className="rounded-[10px] bg-(--color-bg-light) p-6 lg:sticky lg:top-28">
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-(--color-secondary)">
                Próximo passo
              </p>
              <h2 className="mt-3 text-xl font-bold leading-tight text-(--color-bg-dark)">
                Precisa organizar sua operação?
              </h2>
              <p className="mt-3 text-sm leading-relaxed text-(--color-text-muted)">
                A Ecoleva estrutura resíduos, documentação e indicadores com
                rastreabilidade para empresas, eventos e indústrias.
              </p>
              <Button href="/contato" variant="secondary" size="sm" className="mt-5">
                Solicitar diagnóstico
              </Button>
            </aside>
          </div>
        </Section>
      </article>
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
