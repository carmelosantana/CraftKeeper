# CraftKeeper — Design Handoff

Machine-readable specs accompanying the HTML mockups. Read alongside the `.dc.html` screens in the project root.

## Files

- **`design-tokens.json`** — colors (dark + light neutrals, semantic, all 4 accents, provenance, data-viz, syntax), typography, spacing, radius, elevation, layout, motion. Values are applied at runtime as `--ck-*` CSS custom properties.
- **`components.json`** — component inventory: variants, states, tokens consumed, and the design principles/cautions each encodes.
- **`pages.json`** — delivered screen inventory mapping each mockup file to the brief sections and sub-screens it realizes, plus what is not yet mocked.

## Theming

Every screen re-themes live from two axes:

- `theme`: `dark` (default) | `light`
- `accent`: `terracotta` (default) | `emerald` | `slate` | `bronze`

At runtime the selected set is written to `--ck-*` variables on the root element; components read only those variables. To port: emit the same variables from your Appearance setting and drop the inline duplication.

## Non-negotiables baked into the system

- Operational state is honest and specific — never collapse "pending restart" into "saved".
- Status = color **and** shape **and** label (WCAG 2.2 AA); never color alone.
- Every information/action carries provenance.
- AI proposes; the administrator approves. Dangerous actions open review, never execute from search.
- Consequence-first, calm copy — reuse sample strings as voice reference.
