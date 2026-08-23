import { vi } from "vitest";
import type { Mock } from "vitest";

/**
 * `fetch` falso roteado por caminho, para montar as telas do dashboard sem
 * backend. Qualquer rota não declarada devolve 403 — assim um teste que passa
 * por engano em cima de uma chamada não prevista fica visível.
 */

export type ApiRoutes = Record<string, { status?: number; body: unknown }>;

export type ApiMock = {
  fetch: Mock;
  /** Caminhos efetivamente requisitados, na ordem. */
  calls: string[];
  requested: (path: string) => boolean;
};

export function installApiMock(routes: ApiRoutes): ApiMock {
  const calls: string[] = [];

  const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
    const url = typeof input === "string" ? input : input.toString();
    const path = url.split("?")[0];
    calls.push(url);

    const route = routes[path] ?? { status: 403, body: { error: "Acesso negado." } };
    const status = route.status ?? 200;

    return new Response(JSON.stringify(route.body), {
      status,
      headers: { "Content-Type": "application/json" },
    });
  });

  vi.stubGlobal("fetch", fetchMock);

  return {
    fetch: fetchMock,
    calls,
    requested: (path: string) => calls.some((url) => url.split("?")[0] === path),
  };
}

/** Sessão devolvida por `api/auth/me.php`. */
export function sessionOf(role: string, overrides: Record<string, unknown> = {}) {
  return {
    ok: true,
    csrf_token: "a".repeat(64),
    user: {
      id: 7,
      login: `conta-${role}`,
      email: `${role}@exemplo.com`,
      role,
      group_id: 1,
      group_name: "Operação",
      group_powerbi_url: null,
      force_password_change: false,
      ...overrides,
    },
  };
}
