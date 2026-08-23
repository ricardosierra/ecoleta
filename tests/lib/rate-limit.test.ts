import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { checkRateLimit } from "@/lib/rate-limit";

/**
 * O rate limit é o que separa "formulário de contato" de "relay de spam".
 * Os buckets vivem em um Map de módulo, então cada teste usa uma chave própria
 * em vez de tentar limpar o estado global.
 */

const LIMITE = 5;
const JANELA_MS = 60_000;

let contador = 0;
const novaChave = () => `ip-${Date.now()}-${contador++}`;

beforeEach(() => {
  vi.useFakeTimers();
});

afterEach(() => {
  vi.useRealTimers();
});

describe("checkRateLimit", () => {
  it("libera a primeira requisição de uma chave nova", () => {
    expect(checkRateLimit(novaChave())).toEqual({ allowed: true, retryAfterSec: 0 });
  });

  it("libera exatamente 5 requisições e barra a sexta", () => {
    const chave = novaChave();

    for (let i = 0; i < LIMITE; i++) {
      expect(checkRateLimit(chave).allowed).toBe(true);
    }

    const barrada = checkRateLimit(chave);
    expect(barrada.allowed).toBe(false);
    expect(barrada.retryAfterSec).toBeGreaterThan(0);
  });

  it("conta cada chave separadamente", () => {
    const barulhenta = novaChave();
    const quieta = novaChave();

    for (let i = 0; i < LIMITE + 1; i++) {
      checkRateLimit(barulhenta);
    }

    expect(checkRateLimit(barulhenta).allowed).toBe(false);
    expect(checkRateLimit(quieta).allowed).toBe(true);
  });

  it("informa quantos segundos faltam para a janela virar", () => {
    const chave = novaChave();

    for (let i = 0; i < LIMITE; i++) {
      checkRateLimit(chave);
    }

    vi.advanceTimersByTime(20_000);

    expect(checkRateLimit(chave).retryAfterSec).toBe(40);
  });

  it("nunca devolve retryAfterSec zero enquanto está barrando", () => {
    const chave = novaChave();

    for (let i = 0; i < LIMITE; i++) {
      checkRateLimit(chave);
    }

    // A 1 ms do fim da janela o arredondamento para baixo daria 0 segundos, o
    // que o cliente leria como "pode tentar de novo agora".
    vi.advanceTimersByTime(JANELA_MS - 1);

    const barrada = checkRateLimit(chave);
    expect(barrada.allowed).toBe(false);
    expect(barrada.retryAfterSec).toBe(1);
  });

  it("reabre a chave quando a janela expira", () => {
    const chave = novaChave();

    for (let i = 0; i < LIMITE + 1; i++) {
      checkRateLimit(chave);
    }
    expect(checkRateLimit(chave).allowed).toBe(false);

    vi.advanceTimersByTime(JANELA_MS + 1);

    expect(checkRateLimit(chave)).toEqual({ allowed: true, retryAfterSec: 0 });
  });

  it("uma chave barrada não fica barrada para sempre por tentar de novo", () => {
    const chave = novaChave();

    for (let i = 0; i < LIMITE; i++) {
      checkRateLimit(chave);
    }

    // Marteladas durante o bloqueio não devem empurrar a janela para a frente.
    vi.advanceTimersByTime(30_000);
    checkRateLimit(chave);
    checkRateLimit(chave);

    vi.advanceTimersByTime(30_001);

    expect(checkRateLimit(chave).allowed).toBe(true);
  });
});
