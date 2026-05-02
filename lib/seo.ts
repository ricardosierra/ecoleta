import type { Metadata } from "next";
import { siteConfig } from "@/lib/site.config";

type PageMetadata = {
  title: string;
  description: string;
  path: `/${string}`;
  keywords?: string[];
};

const absoluteUrl = (path: string) =>
  new URL(path, siteConfig.url.endsWith("/") ? siteConfig.url : `${siteConfig.url}/`).toString();

export function createPageMetadata({
  title,
  description,
  path,
  keywords = [],
}: PageMetadata): Metadata {
  const url = absoluteUrl(path);
  const fullTitle = `${title} | ${siteConfig.name}`;

  return {
    title,
    description,
    keywords: [...siteConfig.keywords, ...keywords],
    alternates: {
      canonical: path,
    },
    openGraph: {
      type: "website",
      locale: siteConfig.locale,
      url,
      siteName: siteConfig.name,
      title: fullTitle,
      description,
      images: [
        {
          url: "/og-image.png",
          width: 1200,
          height: 630,
          alt: `${fullTitle} - ${siteConfig.tagline}`,
        },
      ],
    },
    twitter: {
      card: "summary_large_image",
      title: fullTitle,
      description,
      images: ["/og-image.png"],
    },
  };
}
