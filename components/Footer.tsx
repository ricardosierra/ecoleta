import Link from "next/link";
import { Children } from "react";
import Logo from "@/components/Logo";
import {
  InstagramIcon,
  LinkedInIcon,
  WhatsAppIcon,
} from "@/components/icons";
import { siteConfig, whatsappLink } from "@/lib/site.config";

export default function Footer() {
  const year = new Date().getFullYear();

  return (
    <footer className="bg-(--color-bg-dark) text-white pt-16 pb-8">
      <div className="container-page">
        <div className="grid gap-10 md:grid-cols-[2fr_1fr_1fr_1.2fr]">
          <div>
            <Logo variant="accent" />
            <p className="mt-4 text-sm text-white/70 max-w-sm leading-relaxed">
              Braço ambiental da Econformidade. Gestão integrada de resíduos
              sólidos, infectantes e têxteis com rastreabilidade digital e
              conformidade ambiental em todo o Estado do Rio de Janeiro.
            </p>
            <div className="mt-6 flex items-center gap-3">
              <a
                href={whatsappLink}
                target="_blank"
                rel="noopener noreferrer"
                aria-label="WhatsApp Ecoleta"
                className="inline-flex items-center justify-center size-10 rounded-full border border-white/15 hover:bg-(--color-accent) hover:text-(--color-bg-dark) hover:border-transparent transition-colors"
              >
                <WhatsAppIcon width={18} height={18} />
              </a>
              <a
                href={siteConfig.social.instagram}
                target="_blank"
                rel="noopener noreferrer"
                aria-label="Instagram Ecoleta"
                className="inline-flex items-center justify-center size-10 rounded-full border border-white/15 hover:bg-(--color-accent) hover:text-(--color-bg-dark) hover:border-transparent transition-colors"
              >
                <InstagramIcon width={18} height={18} />
              </a>
              <a
                href={siteConfig.social.linkedin}
                target="_blank"
                rel="noopener noreferrer"
                aria-label="LinkedIn Ecoleta"
                className="inline-flex items-center justify-center size-10 rounded-full border border-white/15 hover:bg-(--color-accent) hover:text-(--color-bg-dark) hover:border-transparent transition-colors"
              >
                <LinkedInIcon width={18} height={18} />
              </a>
            </div>
          </div>

          <FooterCol title="Navegação">
            {siteConfig.nav.map((n) => (
              <FooterLink key={n.href} href={n.href}>
                {n.label}
              </FooterLink>
            ))}
            <FooterLink href="/">Home</FooterLink>
          </FooterCol>

          <FooterCol title="Institucional">
            <FooterLink href="/contato">Quem somos</FooterLink>
            <FooterLink href="/esg">ESG & Impacto</FooterLink>
            <FooterLink href="/solucoes">Serviços</FooterLink>
            <FooterLink href="/cases">Clientes</FooterLink>
          </FooterCol>

          <FooterCol title="Contato">
            <p className="text-sm text-white/70 leading-relaxed">
              <span className="block text-white/90 mb-1">CNPJ</span>
              {siteConfig.company.cnpj}
            </p>
            <p className="mt-4 text-sm text-white/70 leading-relaxed">
              <span className="block text-white/90 mb-1">Endereço</span>
              {siteConfig.company.address}
            </p>
            <a
              href={whatsappLink}
              target="_blank"
              rel="noopener noreferrer"
              className="mt-4 inline-block text-sm text-(--color-accent) hover:underline"
            >
              (21) 96766-0056
            </a>
            <a
              href={`mailto:${siteConfig.contact.email}`}
              className="mt-2 inline-block text-sm text-(--color-accent) hover:underline"
            >
              {siteConfig.contact.email}
            </a>
          </FooterCol>
        </div>

        <div className="mt-14 pt-6 border-t border-white/10 flex flex-col md:flex-row gap-3 justify-between items-start md:items-center text-xs text-white/50">
          <p>© {year} Ecoleta. Todos os direitos reservados.</p>
          <p className="flex items-center gap-1">
            Site desenvolvido com foco em conformidade e ESG.
          </p>
        </div>
      </div>
    </footer>
  );
}

function FooterCol({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <div>
      <h3 className="text-sm font-semibold uppercase tracking-wider text-white/90 mb-4">
        {title}
      </h3>
      <ul className="flex flex-col gap-3">{wrapItems(children)}</ul>
    </div>
  );
}

function wrapItems(children: React.ReactNode) {
  return Children.toArray(children).map((c, i) => <li key={i}>{c}</li>);
}

function FooterLink({
  href,
  children,
}: {
  href: string;
  children: React.ReactNode;
}) {
  return (
    <Link
      href={href}
      className="text-sm text-white/70 hover:text-(--color-accent) transition-colors"
    >
      {children}
    </Link>
  );
}
