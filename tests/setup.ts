import "@testing-library/jest-dom/vitest";
import { cleanup, configure } from "@testing-library/react";
import { afterEach } from "vitest";

// O padrão de 1s vale para uma tela isolada. Com a suíte inteira rodando,
// dezenas de ambientes jsdom disputam a máquina e uma tela que só espera
// `fetch` resolver estourava esse limite de vez em quando — falha que não
// dizia nada sobre o código.
configure({ asyncUtilTimeout: 5000 });

// O jsdom não implementa scrollIntoView. Os navegadores sim, e as telas o chamam
// (por exemplo, levar o cliente sem fatura até o formulário), então a lacuna é do
// ambiente de teste e não do código.
if (typeof Element !== "undefined" && !Element.prototype.scrollIntoView) {
  Element.prototype.scrollIntoView = () => {};
}

afterEach(() => {
  cleanup();
});
