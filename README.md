# Promo Engine

A promotions engine for WooCommerce, built as a test assignment. The admin creates
promotions (five discount types), the plugin applies them during cart calculation,
shows them on the site (deals page, popup with countdown, mini-cart, checkout) and
tracks their performance in a built-in analytics screen.

Stack: WordPress + WooCommerce, PHP 8. No external services, no CDNs, no dependency
on other plugins. i18n-ready (text domain `promo-engine`).

## Repository layout

```
promo-engine/       ← the plugin itself (this folder is what gets zipped/installed)
tests/              ← standalone unit tests for the discount engine (no WP required)
bin/build-zip.sh    ← builds promo-engine.zip
```

## Installation

1. Copy `promo-engine/` into `wp-content/plugins/` (or upload `promo-engine.zip`
   via Plugins → Add New → Upload).
2. Activate **Promo Engine**. Activation creates:
   - the analytics table `{prefix}pe_events` (indexed, see below);
   - the **/deals/** page containing the `[promo_engine_deals]` shortcode.
3. Load the demo promotions: **Promo Engine → Promotions → "Load demo promotions"**
   button, or via WP-CLI:

   ```
   wp promo-engine seed
   ```

   Re-running is safe — demo promotions are matched by a hidden meta key and
   updated in place. Seeding also creates the standard WooCommerce coupon
   **SAVE10** (−10%) used by spec example 7.

## Demo promotions and category mapping

The spec uses abstract "Category 1/2/3"; on the test site they are mapped to real
categories:

| Spec | Real category (slug) | Promotion |
|------|----------------------|-----------|
| Category 1 | Hoodie (`hoodie`) | **Hoodies −20%** — percent, combines, priority 10, cap 70% |
| — | 5 products (2 from Shorts + 3 from T-Shirts) | **Flash Sale −30%** — percent, exclusive, priority 40, popup with countdown, ends +48h from seeding |
| Category 2 | Shorts (`shorts`) | **Shorts: Buy 2 Get 1 Free** — BOGO, combines, priority 20 |
| Category 3 | T-Shirts (`t-shirts`) | **T-Shirt Bundle: 2 for $250** — bundle, exclusive, priority 30 |
| whole cart | — | **Cart Savings** — $150→−10%, $250→−15%, $400→−20%, priority 5 |

The Flash Sale product list deliberately includes Shorts products so that spec
example 5 (a flash-discounted item inside a BOGO group) is reproducible on the
live site.

## Architecture

### Discount engine (`includes/engine/`)

The calculation core is **pure PHP with zero WordPress dependencies** —
`Discount_Engine` takes cart lines + promotion DTOs and returns final unit prices
with a per-promotion discount breakdown. That makes the money math unit-testable
without a WordPress install:

```
php tests/engine-test.php     # 38 assertions, includes all 9 spec examples
```

The pipeline follows the spec ordering:

1. **Stage 1 — item-level** (percent / fixed) per cart line.
2. **Stage 2 — BOGO / bundles**, computed on prices produced by stage 1. Lines are
   expanded into individual units; each unit participates in at most one group
   promotion.
3. **Stage 3 — cart threshold discount** on the subtotal after stages 1–2. Only
   the highest reached tier applies.
4. **WooCommerce coupons** — untouched, WooCommerce applies them after our prices.
5. **Taxes** — recalculated by WooCommerce on final prices.

All discounts, including the cart-level one, are applied by adjusting line prices
via `set_price()` inside `woocommerce_before_calculate_totals` (priority 999).
Database prices are never modified.

**Why the cart discount is distributed into line prices** (instead of a negative
fee): spec example 7 requires `SAVE10` to yield $184.68 from $205.20 — i.e. the
coupon must see the *post-cart-discount* prices. A fee would leave line subtotals
at $228 and the coupon would compute $22.80 instead of $20.52. Distributing the
cart discount proportionally across line prices makes the standard coupon flow
produce exactly the spec numbers.

**No double application / no recursion:** the original (sale-aware) unit price of
every cart item is captured once per request on the first calculation pass;
every subsequent pass recomputes from that stored base. A re-entrancy flag guards
the calculation itself.

### Combination rule — interpretation

The spec says "if at least one competing promotion is non-stacking → apply only
the highest-priority one", but its own example 5 requires the *non-stacking*
Flash −30% to still combine with the BOGO. These are only consistent if the
combination rule applies **among promotions competing within the same stage**:

- Stage 1: if any applicable percent/fixed promo on an item is non-stacking, only
  the highest-priority one (ties → lower ID) applies; otherwise all apply and
  percentages multiply (`159 × 0.8 × 0.9`).
- Stage 2: group promos are processed in priority order and compete for units
  (a unit is consumed by at most one group promo).
- Stages always compose — that is the documented calculation order.

### Other decisions the spec left open (documented per its section 3 note)

- **Cap** (`max discount %`): bounds the *total* discount produced by a stage on
  an item, measured against the price entering that stage. When several stacked
  promos apply, the strictest (lowest) cap among them wins — example 8
  (−50% ∘ −60% → capped at −70%) works exactly this way.
- **Stacked percent + fixed**: applied sequentially in priority order (percent
  multiplies the running price, fixed subtracts per unit).
- **BOGO unit selection**: merchant-safe — the globally cheapest eligible units
  become the free ones (`floor(units / (X+Y))` groups). Matches both spec
  examples; with e.g. 4 eligible units the cheapest 3 form the group and the most
  expensive unit stays available for other promotions.
- **Bundle**: formed from the most expensive eligible units first; the fixed
  price is distributed across bundle units proportionally to their current
  prices. A bundle that would *raise* the price (pair sum below the bundle
  price) is skipped — the plugin never charges more than without the promo.
- **Cart tiers threshold basis**: the items subtotal *after* stages 1–2 (example
  6 matches $228, the post-BOGO subtotal, against the $150 tier). The mini-cart
  progress bar uses the same basis.
- **Multiple cart-level promos**: same combination rule; stacking ones compose
  multiplicatively, each matching its own tier against the pre-cart-discount
  subtotal.
- **Partial-line prices**: when only part of a line's quantity is discounted
  (BOGO free unit inside a qty-3 line), the line unit price is set to the exact
  average so line totals stay to-the-cent correct; WooCommerce displays the
  averaged unit price.
- **Sale prices**: discounts always start from the current (sale) price, per the
  spec.
- **Service add-ons are not promotion targets**: cart lines flagged as services
  (e.g. the payment add-on's "Order Protection", marked with an
  `order_protection` cart item key) receive no discounts and do not count
  toward cart-threshold tiers. Extensible via the `promo_engine_skip_cart_item`
  filter.

### Promotions storage & admin (Part 1)

Promotions are a custom post type `pe_promotion` (title + a settings meta box) —
this reuses WordPress' battle-tested CRUD UI, capabilities, revisions-free simple
flow and i18n instead of a custom table + hand-rolled list screen. Every
promotion operation (create/edit/publish/delete) is mapped to the
`manage_woocommerce` capability, so only store managers and admins can change
pricing rules. All meta is
saved under `_pe_*` keys with a nonce, capability check and per-field
sanitization. Product selection uses WooCommerce's own AJAX product search
(`wc-product-search`); categories/tags are checkbox lists.

The **active promotions list is cached in a transient** (6h TTL, invalidated on
any promotion save/trash/delete). Date windows are evaluated per request against
the cached list, so scheduled starts/ends don't suffer from the cache. Start/end
datetimes are entered and stored in the **site timezone** and converted to UTC
timestamps when the DTOs are built.

### Frontend (Part 3) — all in the plugin, theme untouched

- **/deals/** — created on activation, renders running promotions with their
  products via the standard WooCommerce loop templates
  (`wc_get_template_part( 'content', 'product' )`), so product cards look native
  to the active theme.
- **Popup** — rendered hidden in `wp_footer`, shown by JS once per session
  (`sessionStorage`), never on checkout. Accessible: `role="dialog"`,
  `aria-modal`, focus is trapped and restored, Esc closes,
  `prefers-reduced-motion` disables the entrance animation (and shows the popup
  without delay). The countdown runs to the promotion's end datetime.
- **Mini-cart** — savings summary + "Add $X more — get −Y%" progress bar rendered
  inside the mini-cart widget (`woocommerce_widget_shopping_cart_before_buttons`),
  so it refreshes through the standard WooCommerce cart fragments. Cart line
  items show old/new prices (`wc_format_sale_price`) and promotion badges.
- **Checkout** — a per-promotion savings breakdown in the order review table
  (`woocommerce_review_order_before_order_total`).
- Scope note: the cart/mini-cart/checkout UI targets the classic (shortcode)
  WooCommerce surfaces — that is what the spec's required hooks (mini-cart
  fragments, review-order rows) exist for, and what the test site's theme uses.
  The block-based Cart/Checkout renders its own React UI; price calculation
  still applies there (the engine runs in `woocommerce_before_calculate_totals`
  for Store API requests too), but the extra info blocks would need separate
  Blocks integration points.

### Analytics (Part 4)

Custom table `{prefix}pe_events`:

```
id, event_type, promo_id, product_id, order_id, variant, revenue, discount, created_at
KEY promo_type_date (promo_id, event_type, created_at)
KEY type_date (event_type, created_at)
KEY order_id (order_id)
```

Events: `popup_view`, `popup_click` (AJAX beacons with a nonce, validated against
the running popup promotion), `add_to_cart` (one per applicable promotion),
`order` (one per involved promotion, with attributed revenue and the promotion's
discount total, copied from order meta at checkout — both classic and Store API
checkouts are hooked).

The admin screen (Promo Engine → Analytics) shows per-promotion popup views,
clicks, CTR, add-to-carts, orders, revenue, discount given and conversion
(orders / add-to-carts), with a date range filter, a per-promotion top-products
table, a popup A/B comparison and a daily time-series chart. The chart is **hand-rolled SVG rendered
server-side** — no external chart libraries, no CDNs. All aggregations are single
`GROUP BY` SQL queries through `$wpdb->prepare()`; nothing is aggregated in PHP
loops. `created_at` is stored in the site timezone so date filters match what the
admin sees.

Revenue attribution: a promotion's revenue = the final totals of the order lines
it touched (the cart-level promo touches all lines). Discount = that promotion's
recorded discount total on the order.

### Bonus features

- **Usage limit** — a promotion can cap how many orders it is used in
  (`Usage limit (orders)` field; empty = unlimited). The counter increments
  once per order at checkout; when the limit is reached the promotion silently
  stops applying everywhere (cart, deals page, popup) and the promotions list
  shows an "Exhausted" badge. The engine treats the limit like the date
  window — an exhausted promotion is filtered out before calculation.
- **Popup A/B test** — two headline variants per popup promotion. When variant
  B is filled in, visitors are split 50/50 (assignment is remembered in
  `sessionStorage` for the session) and every popup view/click event carries
  the variant. The per-promotion analytics screen shows views, clicks and CTR
  for variant A vs B. The demo Flash Sale promotion seeds both variants.
- **Checkout savings breakdown** and **standalone unit tests** for the
  calculation (38 assertions incl. all 9 spec examples) — see above.

### Security & performance checklist

- Nonces on the meta box save, the demo-seed action and the tracking AJAX;
  capability checks everywhere (`edit_post`, `manage_woocommerce`).
- All input sanitized per field (whitelists for selects, `absint` for ID arrays,
  regex-validated datetimes); all output escaped; SQL through `$wpdb->prepare`.
- Active promotions cached in a transient; the cart hook adds no extra queries
  beyond cached term lookups; the events table is write-only on the hot path.
- Uninstall (`uninstall.php`) removes the table, options and promotion posts.

## What maps to the spec checklist

| Spec | Where |
|------|-------|
| Part 1 — CRUD, all 5 types, demo seeding (button + WP-CLI) | `includes/admin/` |
| Part 2 — engine, ordering, combination, cap, sale-aware, no recursion | `includes/engine/`, `includes/cart/` |
| Part 3 — /deals/, popup, mini-cart, checkout breakdown | `includes/frontend/` |
| Part 4 — events table, analytics screen, SQL aggregation, SVG chart | `includes/analytics/`, `includes/admin/class-analytics-page.php` |
| Examples 1–9 | `tests/engine-test.php` (all green) |
| Bonus: checkout breakdown, usage limit, unit tests, popup A/B | all four included |
