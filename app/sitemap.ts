import type { MetadataRoute } from "next";
import { blogPosts } from "@/lib/blog";
import { siteConfig } from "@/lib/site.config";

export const dynamic = "force-static";

export default function sitemap(): MetadataRoute.Sitemap {
  const base = siteConfig.url.replace(/\/$/, "");
  const now = new Date();

  const pages: MetadataRoute.Sitemap = [
    { url: `${base}/`, lastModified: now, changeFrequency: "monthly", priority: 1 },
    { url: `${base}/solucoes`, lastModified: now, changeFrequency: "monthly", priority: 0.9 },
    { url: `${base}/esg`, lastModified: now, changeFrequency: "monthly", priority: 0.9 },
    { url: `${base}/sobre`, lastModified: now, changeFrequency: "monthly", priority: 0.7 },
    { url: `${base}/contato`, lastModified: now, changeFrequency: "monthly", priority: 0.9 },
    { url: `${base}/conteudos`, lastModified: now, changeFrequency: "weekly", priority: 0.6 },
    {
      url: `${base}/politica-de-privacidade`,
      // Data da última revisão do texto, não a do build: a página muda raramente.
      lastModified: new Date(`${siteConfig.legal.privacyPolicyUpdatedAt}T12:00:00Z`),
      changeFrequency: "yearly",
      priority: 0.3,
    },
  ];

  const posts: MetadataRoute.Sitemap = blogPosts.map((post) => ({
    url: `${base}/conteudos/${post.slug}`,
    lastModified: new Date(post.updatedAt),
    changeFrequency: "monthly",
    priority: 0.55,
  }));

  return [...pages, ...posts];
}
