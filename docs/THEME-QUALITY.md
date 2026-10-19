# Theme quality baseline

The default storefront and all nine optional themes import
`plugins/_theme-quality.scss` after their visual tokens. The layer preserves
each theme’s identity while enforcing the shared requirements below.

## Implemented requirements

- Semantic theme aliases for primary, foreground, surface, card, line, focus,
  and button states; component styling no longer depends on the default
  storefront’s black CTA.
- A visible 3px keyboard focus ring, theme-aware dropdown surfaces, readable
  input placeholders, disabled controls, and 44px minimum action targets.
- Mobile checkout actions sized to 48px, product and address text that can wrap
  safely, and carousel controls that remain usable on touch devices.
- `prefers-reduced-motion` disables hover transforms and shortens transitions.
- Aurora, Sound, and Velocity provide dark cart/checkout surfaces and disable
  image multiply blending where it would damage product imagery.

## WCAG AA primary-action check

| Theme | Foreground / background | Contrast |
| --- | --- | --- |
| Default | white / `#000000` | 21.00:1 |
| Atelier | white / `#1f6b5a` | 6.35:1 |
| Aurora | white / `#7150e6` | 5.26:1 |
| Pulse gradient darkest stop | white / `#cc1f62` | 5.33:1 |
| Gallery | white / `#0066cc` | 5.57:1 |
| Signal | `#181a20` / `#fcd535` | 12.18:1 |
| Sound | black / `#1ed760` | 10.94:1 |
| Grove | white / `#00754a` | 5.76:1 |
| Spectrum | white / `#080808` | 20.03:1 |
| Velocity | white / `#e10600` | 4.97:1 |

The previous Aurora and Pulse primary combinations did not meet 4.5:1 with
white text. They were replaced with accessible same-hue steps before release.

## Skills applied

The changes follow accessibility (WCAG keyboard, touch, and motion), usability
(consistent controls and recoverable forms), responsive design, color theory,
web typography, component patterns, and visual-direction guidance. The
`webdesign-review` skill was used to prioritize accessibility and usability
before visual refinements.

## Remaining runtime QA

The repository is not installed, so active-theme browser checks cannot be run
against storefront data yet. After installation, activate each theme and run
the viewport, keyboard, and checkout checks in `THEME-CATALOG.md`.
