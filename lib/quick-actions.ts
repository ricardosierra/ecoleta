/**
 * Atalhos do painel: o botão direito e a busca (Ctrl/⌘K) reaproveitam os
 * botões e links que a tela já desenhou. O clique do menu dispara o mesmo
 * elemento, então cada página continua dona da própria ação.
 */

export type QuickActionGroup = "item" | "tela" | "navegar" | "conta";

export type QuickAction = {
  id: string;
  label: string;
  group: QuickActionGroup;
  element: HTMLElement;
};

export const QUICK_ACTION_GROUP_LABEL: Record<QuickActionGroup, string> = {
  item: "Este item",
  tela: "Nesta tela",
  navegar: "Ir para",
  conta: "Conta",
};

const GROUP_ORDER: QuickActionGroup[] = ["item", "tela", "navegar", "conta"];

const MAX_PER_GROUP = 16;

/** Rótulo acessível curto o bastante para caber no menu. */
export function quickActionLabel(element: HTMLElement): string | null {
  const aria = element.getAttribute("aria-label")?.replace(/\s+/g, " ").trim();
  if (aria) {
    return clip(aria);
  }
  const title = element.getAttribute("title")?.replace(/\s+/g, " ").trim();
  if (title) {
    return clip(title);
  }
  const text = (element.textContent ?? "").replace(/\s+/g, " ").trim();
  if (!text || text.length > 80) {
    return null;
  }

  return text;
}

/**
 * Monta o menu a partir do ponto clicado.
 *
 * Linha de tabela vira "Este item". Fora de uma linha, os botões visíveis do
 * diálogo ou da página viram "Nesta tela". A navegação do cabeçalho entra
 * sempre, para trocar de área sem voltar ao topo.
 */
export function collectQuickActions(doc: Document, target: EventTarget | null): QuickAction[] {
  const hit = target instanceof Element ? target : null;
  const shell = doc.querySelector(".dashboard-shell");
  if (!shell) {
    return [];
  }

  const dialog = hit?.closest("[role='dialog']");
  const pageRoot = dialog ?? doc.querySelector("main.dashboard-main");
  const row = hit?.closest("tbody tr, [data-quick-row]");
  const itemRoot = row ?? (hit && pageRoot ? tightestActionGroup(hit, pageRoot) : null);
  const cluster = itemRoot?.closest("table, ul, ol, [role='list']");

  const actions: QuickAction[] = [];
  const seen = new Set<string>();

  if (itemRoot) {
    pushControls(actions, seen, itemRoot, "item", "item");
  }

  if (pageRoot) {
    pushControls(actions, seen, pageRoot, "tela", "tela", (node) => {
      if (cluster?.contains(node)) {
        return true;
      }
      if (!cluster && itemRoot?.contains(node)) {
        return true;
      }

      return false;
    });
  }

  doc.querySelectorAll("header nav[aria-label='Navegação do dashboard'] a[href]").forEach((node) => {
    if (!(node instanceof HTMLAnchorElement) || !isUsable(node)) {
      return;
    }
    const label = quickActionLabel(node);
    if (!label) {
      return;
    }
    push(actions, seen, {
      id: `nav:${node.getAttribute("href")}`,
      label,
      group: "navegar",
      element: node,
    });
  });

  doc.querySelectorAll("header button").forEach((node) => {
    if (!(node instanceof HTMLButtonElement) || !isUsable(node)) {
      return;
    }
    const label = quickActionLabel(node);
    if (label !== "Sair") {
      return;
    }
    push(actions, seen, {
      id: `conta:${label}`,
      label,
      group: "conta",
      element: node,
    });
  });

  return actions;
}

/** Agrupa na ordem em que o menu desenha os blocos. */
export function groupQuickActions(actions: QuickAction[]): Array<{ group: QuickActionGroup; label: string; actions: QuickAction[] }> {
  return GROUP_ORDER.flatMap((group) => {
    const items = actions.filter((action) => action.group === group);
    if (items.length === 0) {
      return [];
    }

    return [{ group, label: QUICK_ACTION_GROUP_LABEL[group], actions: items }];
  });
}

/** Busca sem acento, para o Ctrl/⌘K. */
export function filterQuickActions(actions: QuickAction[], query: string): QuickAction[] {
  const needle = fold(query.trim());
  if (!needle) {
    return actions;
  }

  return actions.filter((action) => fold(action.label).includes(needle) || fold(QUICK_ACTION_GROUP_LABEL[action.group]).includes(needle));
}

/** Campo de texto usa o menu nativo (copiar, colar, corretor). */
export function prefersNativeContextMenu(target: EventTarget | null): boolean {
  if (!(target instanceof Element)) {
    return false;
  }
  const field = target.closest("input, textarea, select, [contenteditable='true']");
  if (field) {
    return true;
  }
  const selection = target.ownerDocument.defaultView?.getSelection()?.toString() ?? "";

  return selection.trim().length > 0;
}

/** Menor ancestral com um punhado de botões: o cartão ou o item sob o cursor. */
function tightestActionGroup(hit: Element, stop: Element): Element | null {
  let node: Element | null = hit;
  while (node && node !== stop) {
    const count = [...node.querySelectorAll("button, a[href]")].filter(
      (candidate) => candidate instanceof HTMLElement && isUsable(candidate),
    ).length;
    if (count >= 2 && count <= 12) {
      return node;
    }
    if (count > 12) {
      return null;
    }
    node = node.parentElement;
  }

  return null;
}

function pushControls(
  actions: QuickAction[],
  seen: Set<string>,
  root: Element,
  group: QuickActionGroup,
  idPrefix: string,
  skip: (node: HTMLElement) => boolean = () => false,
): void {
  const controls = root.querySelectorAll("button, a[href]");
  controls.forEach((node, index) => {
    if (!(node instanceof HTMLElement) || !isUsable(node)) {
      return;
    }
    if (skip(node)) {
      return;
    }
    if (node.closest("[data-quick-actions]")) {
      return;
    }
    const label = quickActionLabel(node);
    if (!label) {
      return;
    }
    push(actions, seen, {
      id: `${idPrefix}:${index}:${label}`,
      label,
      group,
      element: node,
    });
  });
}

function push(actions: QuickAction[], seen: Set<string>, action: QuickAction): void {
  const count = actions.filter((item) => item.group === action.group).length;
  if (count >= MAX_PER_GROUP) {
    return;
  }
  const key = `${action.group}:${action.id.startsWith("nav:") ? action.id : action.label}`;
  if (seen.has(key)) {
    return;
  }
  seen.add(key);
  actions.push(action);
}

function isUsable(element: HTMLElement): boolean {
  if (element.hidden || element.closest("[hidden]")) {
    return false;
  }
  if (element.getAttribute("aria-hidden") === "true" || element.closest("[aria-hidden='true']")) {
    return false;
  }
  if (element.hasAttribute("disabled") || element.getAttribute("aria-disabled") === "true") {
    return false;
  }
  if (!isLayoutEngine(element.ownerDocument)) {
    return true;
  }
  if (element.getClientRects().length === 0) {
    return false;
  }
  const view = element.ownerDocument.defaultView;
  const style = view?.getComputedStyle(element);
  if (!style) {
    return true;
  }

  return style.display !== "none" && style.visibility !== "hidden";
}

/** jsdom não calcula caixa; o navegador sim. Sem caixa no documento, não escondemos tudo. */
function isLayoutEngine(doc: Document): boolean {
  return doc.documentElement.getClientRects().length > 0;
}

function clip(label: string): string | null {
  return label.length > 80 ? null : label;
}

function fold(value: string): string {
  return value.normalize("NFD").replace(/\p{M}/gu, "").toLowerCase();
}
