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
    { url: `${base}/cases`, lastModified: now, changeFrequency: "monthly", priority: 0.8 },
    { url: `${base}/sobre`, lastModified: now, changeFrequency: "monthly", priority: 0.7 },
    { url: `${base}/contato`, lastModified: now, changeFrequency: "monthly", priority: 0.9 },
    { url: `${base}/conteudos`, lastModified: now, changeFrequency: "weekly", priority: 0.6 },
  ];

  const posts: MetadataRoute.Sitemap = blogPosts.map((post) => ({
    url: `${base}/conteudos/${post.slug}`,
    lastModified: new Date(post.updatedAt),
    changeFrequency: "monthly",
    priority: 0.55,
  }));

  return [...pages, ...posts];
}
