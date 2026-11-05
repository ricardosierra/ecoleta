import { describe, expect, it } from "vitest";
import { collectQuickActions, filterQuickActions, prefersNativeContextMenu } from "@/lib/quick-actions";

function shell(inner: string): Document {
  document.body.innerHTML = `
    <div class="dashboard-shell">
      <header>
        <nav aria-label="Navegação do dashboard">
          <a href="/dashboard">Painel</a>
          <a href="/dashboard/faturas">Faturas</a>
        </nav>
        <button type="button">Sair</button>
      </header>
      <main class="dashboard-main">${inner}</main>
    </div>
  `;

  return document;
}

describe("collectQuickActions", () => {
  it("no botão direito de uma linha oferece as ações daquela linha e a navegação", () => {
    const doc = shell(`
      <button type="button">Nova fatura</button>
      <table>
        <tbody>
          <tr>
            <td>Acme</td>
            <td>
              <button type="button" aria-label="Enviar e-mail">✉</button>
              <button type="button" disabled aria-label="Cancelar">x</button>
            </td>
          </tr>
        </tbody>
      </table>
    `);
    const rowButton = doc.querySelector("tbody button") as HTMLButtonElement;
    const actions = collectQuickActions(doc, rowButton);

    expect(actions.filter((action) => action.group === "item").map((action) => action.label)).toEqual(["Enviar e-mail"]);
    expect(actions.filter((action) => action.group === "tela").map((action) => action.label)).toEqual(["Nova fatura"]);
    expect(actions.filter((action) => action.group === "navegar").map((action) => action.label)).toEqual(["Painel", "Faturas"]);
    expect(actions.some((action) => action.label === "Sair")).toBe(true);
  });

  it("fora de uma linha lista os botões da página, sem os de cada registro", () => {
    const doc = shell(`
      <button type="button">Nova fatura</button>
      <table><tbody><tr><td><button type="button">Cancelar</button></td></tr></tbody></table>
    `);
    const pageButton = doc.querySelector("main button") as HTMLButtonElement;
    const labels = collectQuickActions(doc, pageButton).filter((action) => action.group === "tela").map((action) => action.label);

    expect(labels).toEqual(["Nova fatura"]);
  });

  it("dentro de um diálogo lista os botões do diálogo", () => {
    const doc = shell(`
      <button type="button">Nova fatura</button>
      <div role="dialog">
        <button type="button">Gerar e enviar fatura</button>
      </div>
    `);
    const dialogButton = doc.querySelector("[role='dialog'] button") as HTMLButtonElement;
    const tela = collectQuickActions(doc, dialogButton).filter((action) => action.group === "tela").map((action) => action.label);

    expect(tela).toEqual(["Gerar e enviar fatura"]);
  });

  it("filtra a busca sem acento", () => {
    const doc = shell(`<button type="button">Ordem de serviço</button>`);
    const actions = collectQuickActions(doc, doc.querySelector("main"));

    expect(filterQuickActions(actions, "ordem").map((action) => action.label)).toContain("Ordem de serviço");
  });

  it("no botão direito de um cartão oferece só os botões daquele cartão", () => {
    const doc = shell(`
      <button type="button">Nova conversa</button>
      <ul>
        <li>
          <span>Maria</span>
          <button type="button">Encerrar</button>
          <button type="button">Copiar link</button>
        </li>
        <li>
          <button type="button">Reabrir</button>
        </li>
      </ul>
    `);
    const encerrar = [...doc.querySelectorAll("button")].find((button) => button.textContent === "Encerrar") as HTMLButtonElement;
    const grouped = collectQuickActions(doc, encerrar);

    expect(grouped.filter((action) => action.group === "item").map((action) => action.label)).toEqual(["Encerrar", "Copiar link"]);
    expect(grouped.filter((action) => action.group === "tela").map((action) => action.label)).toEqual(["Nova conversa"]);
  });

  it("deixa o menu nativo em campo de texto", () => {
    const doc = shell(`<input aria-label="Busca" />`);
    expect(prefersNativeContextMenu(doc.querySelector("input"))).toBe(true);
    expect(prefersNativeContextMenu(doc.querySelector("main"))).toBe(false);
  });
});
