import { blogPosts } from "@/lib/blog";
import { siteConfig } from "@/lib/site.config";

export const dynamic = "force-static";

export function GET() {
  const base = siteConfig.url.replace(/\/$/, "");
  const updated = blogPosts
    .map((post) => new Date(post.updatedAt).getTime())
    .sort((a, b) => b - a)[0];

  const items = blogPosts
    .map((post) => {
      const url = `${base}/conteudos/${post.slug}`;

      return `
        <item>
          <title>${escapeXml(post.title)}</title>
          <link>${url}</link>
          <guid isPermaLink="true">${url}</guid>
          <description>${escapeXml(post.description)}</description>
          <category>${escapeXml(post.category)}</category>
          <pubDate>${new Date(post.publishedAt).toUTCString()}</pubDate>
        </item>`;
    })
    .join("");

  const xml = `<?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0">
      <channel>
        <title>Conteúdos Ecoleta</title>
        <link>${base}/conteudos</link>
        <description>${escapeXml("Artigos da Ecoleta sobre gestão de resíduos, MTR, CDF, PGRS, ESG, eventos e economia circular.")}</description>
        <language>pt-BR</language>
        <lastBuildDate>${new Date(updated).toUTCString()}</lastBuildDate>
        ${items}
      </channel>
    </rss>`;

  return new Response(xml.trim(), {
    headers: {
      "Content-Type": "application/rss+xml; charset=utf-8",
    },
  });
}

function escapeXml(value: string) {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&apos;");
}
