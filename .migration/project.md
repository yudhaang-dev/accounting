# Radix UI -> Base UI (whole project)

2026-10-01. Strategy: golden pair via the shadcn CLI (`init --base base --preset nova`, then `add <component> --overwrite` per component). Verdict: migrated; `tsc`, `vp check` and `vp build` pass. Nothing was verified in a browser: there are no screens yet.

## Changed

- `components.json`: style `new-york` -> `base-nova` (base: base). `resources/css/app.css` now carries the `nova` theme and imports `shadcn/tailwind.css` and the Geist font.
- 23 wrappers in `resources/js/components/ui` replaced by their `base-nova` variants: alert, avatar, badge, breadcrumb, button, card, checkbox, collapsible, dialog, dropdown-menu, input, input-otp, label, navigation-menu, select, separator, sheet, sidebar, skeleton, spinner, toggle, toggle-group, tooltip.
- Classification: the local files differed from stock `new-york` and `new-york-v4`, but the differences are registry version drift of an old starter-kit snapshot (checked on tooltip), not user customization, so they were overwritten instead of merged.
- `hooks/use-mobile.tsx` removed; `hooks/use-mobile.ts` comes from the sidebar registry item.
- Dependencies: all `@radix-ui/*` removed; `@base-ui/react`, `shadcn`, `@fontsource-variable/geist` added. Package manager switched npm -> pnpm (ADR-0012).
- Leftover scan: `grep -rn "radix-ui\|@radix-ui\|IconPlaceholder" resources/js` returns nothing.

## Left alone

- `ui/sonner.tsx` and the `sonner` package: intentionally kept as the toast (ADR-0012). The registry's Base UI `toast` was not installed.
- `input-otp` package and `use-appearance` hook: not Radix.
- No consumer code exists yet outside `components/ui`, so there were no call sites (`asChild`, `onValueChange`, ...) to migrate.

## Behavior changes

Not compared at runtime; known Base UI differences to watch for once screens exist:

- Custom triggers use `render`, not `asChild`.
- Menu items (dropdown-menu) and select: item selection and close-on-click semantics follow Base UI.
- Navigation menu has Base UI's own hover delay.
- Tooltips need a `TooltipProvider` ancestor (the CLI printed this for tooltip and sidebar).
- The new theme changes colors, radius and font versus the old `new-york` look.

## Verify by hand

1. Dialog and sheet: focus moves in on open and returns to the trigger on close; Escape closes.
2. Dropdown menu and select: arrow keys, typeahead, Escape; selecting an item closes the menu.
3. Tooltip: appears on hover and keyboard focus, inside a `TooltipProvider`.
4. Sidebar: collapse/expand, mobile sheet (resize below 768px), keyboard shortcut.
5. Input OTP: paste a 6-digit code.
