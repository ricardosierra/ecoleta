import type { MetadataRoute } from "next";
import { siteConfig } from "@/lib/site.config";

export const dynamic = "force-static";

export default function sitemap(): MetadataRoute.Sitemap {
  const base = siteConfig.url.replace(/\/$/, "");
  const now = new Date();

  return [
    { url: `${base}/`, lastModified: now, changeFrequency: "monthly", priority: 1 },
    { url: `${base}/solucoes`, lastModified: now, changeFrequency: "monthly", priority: 0.9 },
    { url: `${base}/esg`, lastModified: now, changeFrequency: "monthly", priority: 0.9 },
    { url: `${base}/cases`, lastModified: now, changeFrequency: "monthly", priority: 0.8 },
    { url: `${base}/contato`, lastModified: now, changeFrequency: "monthly", priority: 0.9 },
  ];
}
