import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch, apiPostJson, clearCsrfToken, setCsrfToken } from "@/lib/dashboard-api";

/**
 * Todo endpoint que altera estado exige o cabeçalho X-CSRF-Token. Este módulo é
 * o único lugar que anexa o token — se ele parar de anexar, ou parar de renovar
 * depois que a sessão é regenerada, o dashboard inteiro passa a responder 403 e
 * nenhuma tela consegue salvar nada.
 */

const TOKEN = "a".repeat(64);
const TOKEN_NOVO = "b".repeat(64);

function jsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

let fetchMock: ReturnType<typeof vi.fn>;

function headerOf(call: number): string | null {
  const init = fetchMock.mock.calls[call][1] as RequestInit | undefined;

  return new Headers(init?.headers).get("X-CSRF-Token");
}

beforeEach(() => {
  clearCsrfToken();
  fetchMock = vi.fn();
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  clearCsrfToken();
});

describe("apiFetch", () => {
  it("não anexa token em GET nem em HEAD", async () => {
    setCsrfToken(TOKEN);
    fetchMock.mockResolvedValue(jsonResponse({ ok: true }));

    await apiFetch("/api/users/index.php");
    await apiFetch("/api/users/index.php", { method: "HEAD" });

    expect(headerOf(0)).toBeNull();
    expect(headerOf(1)).toBeNull();
  });

  it("anexa o token guardado em métodos que alteram estado", async () => {
    setCsrfToken(TOKEN);
    fetchMock.mockResolvedValue(jsonResponse({ ok: true }));

    await apiFetch("/api/users/edit.php", { method: "POST" });

    expect(headerOf(0)).toBe(TOKEN);
    // Nenhuma ida extra ao me.php: o token em memória bastava.
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("busca o token em me.php quando ainda não tem nenhum", async () => {
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ ok: false, csrf_token: TOKEN }))
      .mockResolvedValueOnce(jsonResponse({ ok: true }));

    await apiPostJson("/api/auth/login.php", { username: "a", password: "b" });

    expect(fetchMock.mock.calls[0][0]).toBe("/api/auth/me.php");
    expect(headerOf(1)).toBe(TOKEN);
  });

  it("renova o token e repete uma vez quando o servidor diz que ele venceu", async () => {
    setCsrfToken(TOKEN);
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ error: "expirado", code: "csrf_invalid" }, 403))
      .mockResolvedValueOnce(jsonResponse({ ok: true, csrf_token: TOKEN_NOVO }))
      .mockResolvedValueOnce(jsonResponse({ ok: true }));

    const res = await apiPostJson("/api/groups/delete.php", { group_id: 1 });

    expect(res.status).toBe(200);
    expect(headerOf(0)).toBe(TOKEN);
    expect(fetchMock.mock.calls[1][0]).toBe("/api/auth/me.php");
    expect(headerOf(2)).toBe(TOKEN_NOVO);
  });

  it("não repete a requisição num 403 que não é de CSRF", async () => {
    setCsrfToken(TOKEN);
    fetchMock.mockResolvedValue(jsonResponse({ error: "Acesso negado." }, 403));

    const res = await apiFetch("/api/users/edit.php", { method: "POST" });

    // Um 403 de autorização é resposta final: repetir só gastaria outra volta.
    expect(res.status).toBe(403);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("não entra em laço quando a renovação também falha", async () => {
    setCsrfToken(TOKEN);
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ code: "csrf_invalid" }, 403))
      .mockResolvedValueOnce(jsonResponse({ ok: false }, 401));

    const res = await apiFetch("/api/users/edit.php", { method: "POST" });

    expect(res.status).toBe(403);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it("preserva o corpo e o content-type ao repetir", async () => {
    setCsrfToken(TOKEN);
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ code: "csrf_invalid" }, 403))
      .mockResolvedValueOnce(jsonResponse({ ok: true, csrf_token: TOKEN_NOVO }))
      .mockResolvedValueOnce(jsonResponse({ ok: true }));

    await apiPostJson("/api/users/delete.php", { user_id: 42 });

    const retry = fetchMock.mock.calls[2][1] as RequestInit;
    expect(retry.body).toBe(JSON.stringify({ user_id: 42 }));
    expect(new Headers(retry.headers).get("Content-Type")).toBe("application/json");
  });

  it("segue em frente com token vazio quando me.php está fora do ar", async () => {
    fetchMock
      .mockRejectedValueOnce(new Error("rede caiu"))
      .mockResolvedValueOnce(jsonResponse({ error: "sem token" }, 403));

    const res = await apiFetch("/api/users/edit.php", { method: "POST" });

    // O servidor recusa, que é o certo — mas a tela recebe uma resposta em vez
    // de uma exceção não tratada.
    expect(res.status).toBe(403);
    expect(headerOf(1)).toBe("");
  });
});

describe("setCsrfToken", () => {
  it("ignora valor que não é string não vazia", async () => {
    setCsrfToken(TOKEN);
    setCsrfToken(null);

    fetchMock
      .mockResolvedValueOnce(jsonResponse({ ok: true, csrf_token: TOKEN_NOVO }))
      .mockResolvedValueOnce(jsonResponse({ ok: true }));

    await apiFetch("/api/users/edit.php", { method: "POST" });

    expect(fetchMock.mock.calls[0][0]).toBe("/api/auth/me.php");
    expect(headerOf(1)).toBe(TOKEN_NOVO);
  });
});
