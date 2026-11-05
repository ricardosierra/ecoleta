"use client";

import { useEffect, useId, useMemo, useRef, useState, type KeyboardEvent } from "react";
import {
  collectQuickActions,
  filterQuickActions,
  groupQuickActions,
  prefersNativeContextMenu,
  type QuickAction,
} from "@/lib/quick-actions";

type MenuState = {
  x: number;
  y: number;
  mode: "context" | "palette";
  actions: QuickAction[];
};

/**
 * Botão direito e Ctrl/⌘K no painel. As ações são as que a tela já mostra:
 * a linha sob o cursor, os botões da página e os links do cabeçalho.
 */
export function DashboardQuickActions() {
  const [menu, setMenu] = useState<MenuState | null>(null);
  const [query, setQuery] = useState("");
  const [active, setActive] = useState(0);
  const panelRef = useRef<HTMLDivElement>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const titleId = useId();

  useEffect(() => {
    const onContext = (event: MouseEvent) => {
      if (event.target instanceof Element && event.target.closest("[data-quick-actions]")) {
        event.preventDefault();
        return;
      }
      if (prefersNativeContextMenu(event.target)) {
        return;
      }
      const actions = collectQuickActions(document, event.target);
      if (actions.length === 0) {
        return;
      }
      event.preventDefault();
      setQuery("");
      setActive(0);
      setMenu({ x: event.clientX, y: event.clientY, mode: "context", actions });
    };

    const onKey = (event: globalThis.KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
        event.preventDefault();
        const actions = collectQuickActions(document, document.querySelector("main.dashboard-main"));
        setQuery("");
        setActive(0);
        setMenu({
          x: Math.round(window.innerWidth / 2),
          y: Math.round(window.innerHeight / 5),
          mode: "palette",
          actions,
        });
      }
    };

    document.addEventListener("contextmenu", onContext);
    document.addEventListener("keydown", onKey);

    return () => {
      document.removeEventListener("contextmenu", onContext);
      document.removeEventListener("keydown", onKey);
    };
  }, []);

  const visible = useMemo(
    () => (menu ? filterQuickActions(menu.actions, menu.mode === "palette" ? query : "") : []),
    [menu, query],
  );
  const groups = useMemo(() => groupQuickActions(visible), [visible]);

  useEffect(() => {
    if (!menu) {
      return;
    }
    const node = menu.mode === "palette" ? searchRef.current : panelRef.current?.querySelector<HTMLButtonElement>("button[data-quick-item]");
    node?.focus();
  }, [menu]);

  useEffect(() => {
    setActive(0);
  }, [query, menu]);

  if (!menu) {
    return null;
  }

  const run = (action: QuickAction) => {
    setMenu(null);
    action.element.click();
  };

  const onPanelKey = (event: KeyboardEvent) => {
    if (event.key === "Escape") {
      event.preventDefault();
      setMenu(null);
      return;
    }
    if (visible.length === 0) {
      return;
    }
    if (event.key === "ArrowDown") {
      event.preventDefault();
      setActive((index) => (index + 1) % visible.length);
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      setActive((index) => (index - 1 + visible.length) % visible.length);
    } else if (event.key === "Enter") {
      event.preventDefault();
      const action = visible[active];
      if (action) {
        run(action);
      }
    }
  };

  const left = Math.max(8, Math.min(menu.x, window.innerWidth - 280));
  const top = Math.max(8, Math.min(menu.y, window.innerHeight - 320));
  let cursor = 0;

  return (
    <div className="fixed inset-0 z-[60] print:hidden" data-quick-actions>
      <button
        type="button"
        aria-label="Fechar atalhos"
        className="absolute inset-0 cursor-default bg-black/40"
        onClick={() => setMenu(null)}
      />
      <div
        ref={panelRef}
        role="menu"
        aria-labelledby={titleId}
        onKeyDown={onPanelKey}
        style={{ left, top }}
        className="absolute w-[17rem] overflow-hidden rounded-[var(--radius-card)] border border-[var(--color-border-dark)] bg-[var(--color-bg-dark)] shadow-2xl"
      >
        <p id={titleId} className="sr-only">
          {menu.mode === "palette" ? "Buscar ação" : "Ações"}
        </p>
        {menu.mode === "palette" && (
          <input
            ref={searchRef}
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="Buscar ação ou tela"
            aria-label="Buscar ação ou tela"
            className="w-full border-b border-[var(--color-border-dark)] bg-transparent px-3 py-2.5 text-sm text-white outline-none placeholder:text-white/40"
          />
        )}
        <div className="max-h-72 overflow-y-auto py-1">
          {groups.length === 0 && (
            <p className="px-3 py-3 text-sm text-white/50">Nenhuma ação com esse texto.</p>
          )}
          {groups.map((group) => (
            <div key={group.group} className="py-1">
              <p className="px-3 pb-1 text-[10px] font-semibold uppercase tracking-wider text-white/45">
                {group.label}
              </p>
              {group.actions.map((action) => {
                const index = cursor;
                cursor += 1;
                const current = index === active;

                return (
                  <button
                    key={action.id}
                    type="button"
                    role="menuitem"
                    data-quick-item
                    className={`block w-full px-3 py-2 text-left text-sm ${
                      current ? "bg-[var(--color-accent-soft)] text-[var(--color-accent)]" : "text-white/90 hover:bg-white/5"
                    }`}
                    onMouseEnter={() => setActive(index)}
                    onClick={() => run(action)}
                  >
                    {action.label}
                  </button>
                );
              })}
            </div>
          ))}
        </div>
        <p className="border-t border-[var(--color-border-dark)] px-3 py-2 text-[11px] text-white/40">
          {menu.mode === "palette" ? "Esc fecha" : "Ctrl+K ou ⌘K busca em qualquer tela"}
        </p>
      </div>
    </div>
  );
}
