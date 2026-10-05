import { act, render } from "@testing-library/react";
import { renderToString } from "react-dom/server";
import { afterEach, describe, expect, it, vi } from "vitest";
import HeroVideo from "@/components/HeroVideo";

/**
 * O vídeo de fundo da home pesa cerca de 14 MB. Quem pediu ao sistema menos
 * movimento não pode receber um vídeo em loop, e ninguém deveria baixar dois
 * formatos nem o maior deles primeiro.
 */

type Ouvinte = () => void;

/**
 * O jsdom não implementa matchMedia. Este stub responde só à consulta de
 * movimento reduzido e deixa o teste virar a preferência com `muda()`.
 */
function instalaPreferencia(reduzido: boolean) {
  let atual = reduzido;
  const ouvintes = new Set<Ouvinte>();

  vi.stubGlobal(
    "matchMedia",
    vi.fn((query: string) => ({
      get matches() {
        return query.includes("prefers-reduced-motion") ? atual : false;
      },
      media: query,
      addEventListener: (_tipo: string, fn: Ouvinte) => ouvintes.add(fn),
      removeEventListener: (_tipo: string, fn: Ouvinte) => ouvintes.delete(fn),
    }))
  );

  return {
    muda: (valor: boolean) => {
      atual = valor;
      ouvintes.forEach((fn) => fn());
    },
  };
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("HeroVideo", () => {
  it("não renderiza vídeo para quem pediu movimento reduzido", () => {
    instalaPreferencia(true);

    const { container } = render(<HeroVideo className="fundo" />);

    expect(container.querySelector("video")).toBeNull();
  });

  it("renderiza o vídeo, mudo e em loop, para quem não pediu", () => {
    instalaPreferencia(false);

    const { container } = render(<HeroVideo className="fundo" />);

    const video = container.querySelector("video");
    expect(video).not.toBeNull();
    expect(video).toHaveClass("fundo");
    expect(video?.autoplay).toBe(true);
    expect(video?.loop).toBe(true);
    expect(video?.muted).toBe(true);
  });

  it("pede só os metadados antes de tocar", () => {
    instalaPreferencia(false);

    const { container } = render(<HeroVideo />);

    expect(container.querySelector("video")).toHaveAttribute("preload", "metadata");
  });

  it("oferece o mp4 antes do webm, que é o arquivo maior", () => {
    instalaPreferencia(false);

    const { container } = render(<HeroVideo />);

    const fontes = Array.from(container.querySelectorAll("source")).map((s) => s.getAttribute("src"));
    expect(fontes).toEqual(["/hero-video.mp4", "/hero-video.webm"]);
  });

  it("aplica a velocidade de reprodução pedida", () => {
    instalaPreferencia(false);

    const { container } = render(<HeroVideo playbackRate={0.5} />);

    expect(container.querySelector("video")?.playbackRate).toBe(0.5);
  });

  it("tira o vídeo da tela quando a pessoa liga o movimento reduzido com a página aberta", () => {
    const pref = instalaPreferencia(false);
    const { container } = render(<HeroVideo />);
    expect(container.querySelector("video")).not.toBeNull();

    act(() => pref.muda(true));

    expect(container.querySelector("video")).toBeNull();
  });

  it("devolve o vídeo quando o movimento reduzido é desligado", () => {
    const pref = instalaPreferencia(true);
    const { container } = render(<HeroVideo />);
    expect(container.querySelector("video")).toBeNull();

    act(() => pref.muda(false));

    expect(container.querySelector("video")).not.toBeNull();
  });

  it("não coloca o vídeo no HTML estático: a preferência só é conhecida no navegador", () => {
    // O site é export estático. Um <video autoplay> no HTML começaria a baixar
    // antes de o JavaScript poder olhar a preferência de movimento.
    const html = renderToString(<HeroVideo />);

    expect(html).not.toContain("<video");
  });
});
