# BlueHub Dashboard Design System (CSS Tokens)

Modern AI / fintech SaaS aesthetic (Stripe / Linear / Vercel style).

## Principles

-   **Light-first**: light theme is the default.
-   **Dark-ready**: dark theme is opt-in later via `html[data-theme="dark"]`.
-   **Token-driven**: components should prefer CSS variables over hard-coded values.
-   **Subtle elevation**: shadows are small and restrained; use borders + surfaces first.

## Design tokens

Tokens live in `src/styles/tokens.css` and are imported by `src/style.css`.

### Color palette

-   **Neutrals**

    -   `--color-bg` (app background)
    -   `--color-surface` (cards/panels)
    -   `--color-surface-2` (hovered/secondary surfaces)
    -   `--color-text` (primary text)
    -   `--color-muted` (secondary text)
    -   `--color-border` (dividers/borders)

-   **Brand / semantic**

    -   `--color-primary`
    -   `--color-secondary`
    -   `--color-success`
    -   `--color-warning`
    -   `--color-error`

-   **Focus ring**
    -   `--ring` (ring color)
    -   `--ring-offset` (ring offset background)

Dark-ready overrides exist under `:root[data-theme="dark"]`.

### Typography scale

-   **Base scale**: `--text-xs`, `--text-sm`, `--text-md`, `--text-lg`, `--text-xl`, `--text-2xl`, `--text-3xl`
-   **Semantic aliases** (preferred in components)
    -   `--text-caption`, `--text-body`, `--text-h3`, `--text-h2`, `--text-h1`
-   **Line heights**: `--leading-tight`, `--leading-normal`
-   **Weights**: `--weight-regular`, `--weight-medium`, `--weight-bold`

Guideline:

-   **Headings**: `--text-h1/2/3` with `--leading-tight`
-   **Body**: `--text-body` with `--leading-normal`
-   **Captions**: `--text-caption` (often muted)

### Spacing system

4px-based scale:

-   `--space-1` (4px) → `--space-12` (48px)

Guideline:

-   Prefer `--space-4`/`--space-6` for page padding.
-   Prefer `--space-2`/`--space-3` for tight component gaps.

### Border radius

-   `--radius-sm` (small controls)
-   `--radius-md` (default controls)
-   `--radius-lg` (cards/menus)
-   `--radius-pill` (badges)

Guideline:

-   Inputs/buttons: `--radius-md`
-   Cards/menus: `--radius-lg`
-   Badges: `--radius-pill`

### Shadow & elevation

-   `--shadow-sm`: subtle separation (inputs, badges, buttons)
-   `--shadow-md`: elevated surfaces (popovers)
-   `--shadow-lg`: prominent surfaces (menus)

Guideline:

-   Default to **border + surface**; add shadows only where needed.
-   Use at most **one** shadow level per component.

### Motion

-   `--duration-fast`
-   `--ease-standard`

Guideline:

-   Keep motion subtle; avoid long durations.

## Component styles (global classes)

These are implemented in `src/style.css`.

### Buttons

Base: `.btn`

-   Default: neutral surface button
-   States: hover lifts slightly; disabled reduces opacity

Variants:

-   `.btn--primary` (brand)
-   `.btn--secondary` (tinted surface)
-   `.btn--ghost` (transparent, for toolbars)

Guideline:

-   **Primary**: one per view/section; the main action.
-   **Secondary**: supportive actions.
-   **Ghost**: navigation/toolbars, low emphasis actions.

### Badges

Base: `.badge`

Statuses:

-   `.badge--processing`
-   `.badge--completed`
-   `.badge--failed`

Guideline:

-   Use badges to communicate state at a glance.
-   Prefer a short label (“Processing”, “Ready”, “Failed”).

## Hover & focus states

-   **Focus**: consistent focus ring is applied globally via `:focus-visible`.
-   **Hover**:
    -   Buttons: small lift + background shift
    -   Ghost buttons: subtle tinted hover background
    -   Links: underline on hover

Guideline:

-   Never remove focus styles.
-   Prefer `:focus-visible` for keyboard-only rings.

## Dark-ready later

To enable dark mode later, set:

-   `document.documentElement.dataset.theme = "dark"`

The token file already includes dark overrides in `:root[data-theme="dark"]`.
