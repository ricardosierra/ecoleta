import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

/**
 * O rodapé mostra um telefone e o transforma em link de WhatsApp. Os dois têm de
 * sair do MESMO valor do site.config: com o número fixo no JSX, trocar
 * NEXT_PUBLIC_WHATSAPP_NUMBER mudava o link e deixava o texto antigo na tela.
 */

async function carregaFooter(numero?: string) {
  vi.resetModules();
  vi.stubEnv("NEXT_PUBLIC_WHATSAPP_NUMBER", numero ?? "");
  vi.stubEnv("NEXT_PUBLIC_PHONE_COMERCIAL", "");
  const { default: Footer } = await import("@/components/Footer");

  return Footer;
}

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("Footer", () => {
  it("mostra o telefone do padrão do site, com o link para o mesmo número", async () => {
    const Footer = await carregaFooter();

    render(<Footer />);

    const link = screen.getByRole("link", { name: "(21) 99152-9383" });
    expect(link).toHaveAttribute("href", expect.stringContaining("https://wa.me/5521991529383?"));
  });

  it("acompanha o número configurado em vez de um texto fixo", async () => {
    const Footer = await carregaFooter("5511999998888");

    render(<Footer />);

    const link = screen.getByRole("link", { name: "(11) 99999-8888" });
    expect(link).toHaveAttribute("href", expect.stringContaining("https://wa.me/5511999998888?"));
    expect(screen.queryByText("(21) 99152-9383")).toBeNull();
  });

  it("número de exemplo malformado não deixa o rodapé com link para wa.me/55", async () => {
    const Footer = await carregaFooter("55XXXXXXXXXXX");

    render(<Footer />);

    const link = screen.getByRole("link", { name: "(21) 99152-9383" });
    expect(link.getAttribute("href")).not.toMatch(/wa\.me\/55\?/);
    expect(link).toHaveAttribute("href", expect.stringContaining("https://wa.me/5521991529383?"));
  });
});
