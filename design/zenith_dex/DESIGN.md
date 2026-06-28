---
name: Zenith Dex
colors:
  surface: '#f9f9fc'
  surface-dim: '#dadadc'
  surface-bright: '#f9f9fc'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f3f3f6'
  surface-container: '#eeeef0'
  surface-container-high: '#e8e8ea'
  surface-container-highest: '#e2e2e5'
  on-surface: '#1a1c1e'
  on-surface-variant: '#603e39'
  inverse-surface: '#2f3133'
  inverse-on-surface: '#f0f0f3'
  outline: '#956d67'
  outline-variant: '#ebbbb4'
  surface-tint: '#c00100'
  primary: '#bc0100'
  on-primary: '#ffffff'
  primary-container: '#eb0000'
  on-primary-container: '#fffbff'
  inverse-primary: '#ffb4a8'
  secondary: '#0001c0'
  on-secondary: '#ffffff'
  secondary-container: '#080cff'
  on-secondary-container: '#b6baff'
  tertiary: '#6d5e00'
  on-tertiary: '#ffffff'
  tertiary-container: '#c4ab00'
  on-tertiary-container: '#4a3f00'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#ffdad4'
  primary-fixed-dim: '#ffb4a8'
  on-primary-fixed: '#410000'
  on-primary-fixed-variant: '#930100'
  secondary-fixed: '#e0e0ff'
  secondary-fixed-dim: '#bec2ff'
  on-secondary-fixed: '#00006e'
  on-secondary-fixed-variant: '#0000ef'
  tertiary-fixed: '#ffe24a'
  tertiary-fixed-dim: '#e3c600'
  on-tertiary-fixed: '#211b00'
  on-tertiary-fixed-variant: '#524600'
  background: '#f9f9fc'
  on-background: '#1a1c1e'
  surface-variant: '#e2e2e5'
typography:
  display-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 48px
    fontWeight: '800'
    lineHeight: 56px
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
    letterSpacing: -0.01em
  headline-lg-mobile:
    fontFamily: Plus Jakarta Sans
    fontSize: 24px
    fontWeight: '700'
    lineHeight: 32px
  title-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
  body-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  body-sm:
    fontFamily: Plus Jakarta Sans
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  label-mono:
    fontFamily: JetBrains Mono
    fontSize: 12px
    fontWeight: '500'
    lineHeight: 16px
    letterSpacing: 0.05em
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  base: 8px
  container-padding: 24px
  gutter: 16px
  stack-sm: 4px
  stack-md: 12px
  stack-lg: 32px
---

## Brand & Style
The design system establishes a "Modern Playful" aesthetic, balancing the high-energy nostalgia of Pokémon with the precision of a high-end fintech application. It is designed for serious collectors who require professional-grade data visualization and market tracking without losing the joy of the franchise.

The style leverages **Minimalism** as a foundation—utilizing vast white space and a rigorous grid—while injecting **Corporate / Modern** reliability. This is punctuated by vibrant, high-contrast accents and tactile, physical-inspired containers that mimic the premium feel of a slabbed, graded card. The emotional response is one of organized excitement: the app feels like a curated digital vault.

## Colors
The palette utilizes the iconic "Master Ball" trio but applies them with restraint to maintain a premium feel. 
- **Primary (Poké Red):** Reserved for critical actions, branding moments, and fire-type categorization.
- **Secondary (Great Blue):** Used for market trends, data visualization, and water-type categorization.
- **Tertiary (Ultra Yellow):** An accent color for "Rare" highlights, star ratings, and sparking interest in specific UI details.
- **Neutral:** A deep carbon black is used for typography to ensure high legibility. Backgrounds remain predominantly white or very light gray (#F8F9FA) to let the colorful card artwork remain the focal point.

## Typography
This design system uses **Plus Jakarta Sans** for its friendly yet professional geometric construction. Its rounded terminals echo the circular motifs of the brand while maintaining excellent readability at small sizes. 

For technical data, market prices, and serial numbers, **JetBrains Mono** is introduced to provide a "technical tool" feel, suggesting precision in the market value calculations. Use `label-mono` for all numeric data points and secondary metadata. Headlines should use tight letter-spacing and heavy weights to feel impactful and modern.

## Layout & Spacing
The system follows an **8px linear scale**. Layouts are built on a **12-column fluid grid** for desktop and a **4-column fluid grid** for mobile. 

To emphasize the "premium collector" feel, the design system utilizes generous "Safe Zones" around card images (minimum 24px) to prevent the UI from feeling cluttered. Content blocks should be stacked with `stack-lg` spacing to define clear topical sections (e.g., separating "Collection Value" from "Recent Acquisitions").

## Elevation & Depth
Depth is created through **Tonal Layers** and **Ambient Shadows**. 
- **Surface Level 0:** The main canvas, pure white.
- **Surface Level 1 (Cards):** Subtly off-white (#FFFFFF) with a soft, diffused shadow (0px 4px 20px rgba(0,0,0,0.05)) and a 1px neutral border (#E9ECEF).
- **Surface Level 2 (Floating Modals):** High-contrast depth with a more aggressive shadow (0px 12px 40px rgba(0,0,0,0.12)) to indicate priority.

Interactive elements like buttons use a "Pressed" state that removes the shadow and applies a 2px inner-glow to simulate a physical push-button feel.

## Shapes
The shape language is primarily **Rounded**. 
- Standard components (inputs, buttons) use a 0.5rem (8px) radius.
- Card containers and main feature blocks use a 1rem (16px) radius to create a soft, inviting frame for the rectangular card art. 
- Circular elements are used exclusively for status indicators, progress trackers, and the primary "Add Card" action button to mimic the shape of a Pokéball.

## Components
- **Primary Buttons:** Solid fill using Primary Red. Text is white, bold, and uppercase. Use a slight bounce animation on hover.
- **Data Chips:** Small, pill-shaped badges with `label-mono` text. Background colors should reflect the Pokémon type (e.g., Grass = Green, Psychic = Purple) but with a 10% opacity tint and 100% saturation text for readability.
- **Collector Cards:** The core component. Features a large image container with a fixed aspect ratio (2.5:3.5), followed by a clean white area for the name (`title-md`) and market price (`label-mono` in Secondary Blue).
- **Market Graph:** Minimalist line charts using a 2px stroke width. No fill under the line to keep the UI clean.
- **Input Fields:** Flat design with a subtle light-gray background (#F1F3F5) and a 2px bottom-border that turns Primary Red on focus.
- **Price Indicator:** Positive trends in Green, negative in Red, but always accompanied by a small geometric arrow icon for accessibility.