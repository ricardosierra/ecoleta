import "@testing-library/jest-dom/vitest";
import { cleanup, configure } from "@testing-library/react";
import { afterEach } from "vitest";

// O padrão de 1s vale para uma tela isolada. Com a suíte inteira rodando,
// dezenas de ambientes jsdom disputam a máquina e uma tela que só espera
// `fetch` resolver estourava esse limite de vez em quando — falha que não
// dizia nada sobre o código.
configure({ asyncUtilTimeout: 5000 });

afterEach(() => {
  cleanup();
});
