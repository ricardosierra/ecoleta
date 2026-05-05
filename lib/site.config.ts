/**
 * Configuração central da Ecoleta.
 *
 * Placeholders devem ser substituídos antes do go-live:
 * - WHATSAPP_NUMBER, INSTAGRAM_URL, LINKEDIN_URL
 * - CNPJ, ENDERECO
 * - CONTACT_TO_EMAIL (também via env)
 */

export const siteConfig = {
  name: "Ecoleta",
  legalName: "Ecoleta Soluções Ambientais",
  technicalPartners: [
    { name: "Rica Soluções", url: "https://ricasolucoes.com.br" },
    { name: "Sierra Tecnologia", url: "https://sierratecnologia.com.br" },
  ],
  tagline: "Gestão de resíduos com rastreabilidade e impacto ESG",
  description:
    "Gestão completa de resíduos para empresas, eventos e indústrias. Rastreabilidade, conformidade ambiental, MTR, CDF, relatórios ESG e redução do envio ao aterro.",
  url: process.env.NEXT_PUBLIC_SITE_URL || "https://www.ecoleta.com",
  locale: "pt_BR",
  keywords: [
    "gestão de resíduos",
    "resíduos sólidos",
    "ESG",
    "rastreabilidade ambiental",
    "MTR",
    "CDF",
    "PGRS",
    "conformidade ambiental",
    "coleta de resíduos",
    "Ecoleta",
    "Econformidade",
  ],

  contact: {
    /** E-mail de destino do formulário (também sobrescrevível via env CONTACT_TO_EMAIL). */
    email: process.env.NEXT_PUBLIC_CONTACT_EMAIL || "diretoria@econformidade.com.br",
    /** Número de WhatsApp em formato internacional sem caracteres especiais. */
    whatsappNumber: process.env.NEXT_PUBLIC_WHATSAPP_NUMBER || "5521991529383",
    whatsappMessage: "Olá! Gostaria de falar com um especialista da Ecoleta.",
  },

  social: {
    instagram:
      process.env.NEXT_PUBLIC_INSTAGRAM_URL || "https://instagram.com/ecoleta.eco",
    linkedin:
      process.env.NEXT_PUBLIC_LINKEDIN_URL ||
      "https://linkedin.com/company/econformidade",
  },

  company: {
    cnpj: process.env.NEXT_PUBLIC_CNPJ || "57.772.812/0001-72",
    address: process.env.NEXT_PUBLIC_ADDRESS || "Endereço a definir",
    phoneComercial: process.env.NEXT_PUBLIC_PHONE_COMERCIAL || "(21) 99152-9383",
    areaServed: "Brasil",
  },

  nav: [
    { label: "Soluções", href: "/solucoes" },
    { label: "ESG", href: "/esg" },
    { label: "Cases", href: "/cases" },
    { label: "Sobre", href: "/sobre" },
    { label: "Contato", href: "/contato" },
  ],
} as const;

export const whatsappLink = (() => {
  const number = siteConfig.contact.whatsappNumber.replace(/\D/g, "");
  const text = encodeURIComponent(siteConfig.contact.whatsappMessage);
  return `https://wa.me/${number}?text=${text}`;
})();
