import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";

/**
 * Suíte de front. Roda em jsdom porque metade dos testes monta componentes;
 * os módulos puros de lib/ não se importam com o ambiente.
 */
export default defineConfig({
  plugins: [react()],
  resolve: {
    // Resolve o alias `@/*` do tsconfig.json — mesma raiz do Next.
    tsconfigPaths: true,
  },
  test: {
    environment: "jsdom",
    setupFiles: ["./tests/setup.ts"],
    include: ["tests/**/*.test.{ts,tsx}"],
    // Cada teste começa com os espiões e os stubs globais limpos.
    restoreMocks: true,
    unstubEnvs: true,
    unstubGlobals: true,
  },
});
