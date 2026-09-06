# Graph Report - app/dashboard  (2026-09-05)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 50 nodes · 38 edges · 12 communities (5 shown, 7 thin omitted)
- Extraction: 100% EXTRACTED · 0% INFERRED · 0% AMBIGUOUS
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `de07e441`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- ver/page.tsx
- os/page.tsx
- usuarios/page.tsx
- clientes/page.tsx
- grupos/page.tsx
- indicadores/page.tsx
- faturas/page.tsx
- page.tsx
- empresas/page.tsx

## God Nodes (most connected - your core abstractions)
1. `Client` - 1 edges
2. `Company` - 1 edges
3. `Group` - 1 edges
4. `Indicator` - 1 edges
5. `HistoryLog` - 1 edges
6. `Group` - 1 edges
7. `User` - 1 edges
8. `Group` - 1 edges
9. `User` - 1 edges
10. `ActivityLog` - 1 edges

## Surprising Connections (you probably didn't know these)
- None detected - all connections are within the same source files.

## Import Cycles
- None detected.

## Communities (12 total, 7 thin omitted)

### Community 0 - "ver/page.tsx"
Cohesion: 0.33
Nodes (3): ActivityLog, Group, User

### Community 1 - "os/page.tsx"
Cohesion: 0.33
Nodes (3): Client, Feedback, ReenvioWhatsApp

## Knowledge Gaps
- **15 isolated node(s):** `Client`, `Company`, `Group`, `Indicator`, `HistoryLog` (+10 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **7 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **What connects `Client`, `Company`, `Group` to the rest of the system?**
  _15 weakly-connected nodes found - possible documentation gaps or missing edges._