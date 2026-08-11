"use client";

import { usePathname } from "next/navigation";
import Footer from "@/components/Footer";
import Header from "@/components/Header";
import WhatsAppFloatingButton from "@/components/WhatsAppFloatingButton";

type SiteChromeProps = {
  children: React.ReactNode;
};

export function SiteChrome({ children }: SiteChromeProps) {
  const pathname = usePathname();

  if (pathname.startsWith("/dashboard")) {
    return children;
  }

  return (
    <>
      <Header />
      <main className="flex-1 pt-[72px] md:pt-[88px]">{children}</main>
      <Footer />
      <WhatsAppFloatingButton />
    </>
  );
}
