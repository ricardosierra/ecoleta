/**
 * Cliente do backend PHP do dashboard (`public/api/`).
 *
 * Todo endpoint que não é GET exige o cabeçalho `X-CSRF-Token` com o token da
 * sessão, emitido por `api/auth/me.php`. Este módulo guarda o token em memória,
 * busca sozinho quando falta e refaz a requisição uma única vez se o servidor
 * responder que o token expirou (o que acontece quando a sessão é regenerada).
 */

const CSRF_HEADER = "X-CSRF-Token";
const ME_ENDPOINT = "/api/auth/me.php";

let csrfToken: string | null = null;

export function setCsrfToken(token: unknown): void {
  csrfToken = typeof token === "string" && token.length > 0 ? token : null;
}

export function clearCsrfToken(): void {
  csrfToken = null;
}

/** Lê o token atual da sessão. `me.php` devolve o token mesmo sem login. */
async function fetchCsrfToken(): Promise<string | null> {
  try {
    const res = await fetch(ME_ENDPOINT, { headers: { Accept: "application/json" } });
    const data = await res.json().catch(() => null);
    setCsrfToken(data?.csrf_token);
  } catch {
    csrfToken = null;
  }

  return csrfToken;
}

/**
 * `fetch` com o token CSRF anexado. GET e HEAD passam direto — o backend só
 * cobra o token em métodos que alteram estado.
 */
export async function apiFetch(input: string, init: RequestInit = {}): Promise<Response> {
  const method = (init.method ?? "GET").toUpperCase();

  if (method === "GET" || method === "HEAD") {
    return fetch(input, init);
  }

  if (!csrfToken) {
    await fetchCsrfToken();
  }

  const send = () => {
    const headers = new Headers(init.headers);
    headers.set(CSRF_HEADER, csrfToken ?? "");
    return fetch(input, { ...init, headers });
  };

  const res = await send();

  // Token vencido (sessão regenerada em outra aba, por exemplo): renova e
  // tenta de novo uma única vez, sem que a tela precise saber disso.
  if (res.status === 403) {
    const peek = await res
      .clone()
      .json()
      .catch(() => null);

    if (peek?.code === "csrf_invalid" && (await fetchCsrfToken())) {
      return send();
    }
  }

  return res;
}

/** Atalho para POST com corpo JSON — o formato usado por todos os endpoints. */
export function apiPostJson(input: string, body: unknown): Promise<Response> {
  return apiFetch(input, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
}
