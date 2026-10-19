# Flexible Flat Shipping

The visual rule editor supports fixed, percentage, weight-tier, quantity-tier, and free-shipping rates. Each method can be constrained by country, region, exact postal code, order amount, cart weight, item quantity, and an optional free-shipping threshold.

Rules are validated again on the server when saved. Invalid codes, negative or non-finite amounts, reversed ranges, invalid billing types, and zero-sized tier steps are rejected. Runtime normalization remains defensive for settings created by older versions or imported directly.

Country and zone values are ISO codes from the store's country and region settings. A fresh installation exposes the backward-compatible `flat_shipping.0` fallback until the visual rules are saved. Persisted rule codes remain stable as address eligibility changes, including legacy configurations that contain duplicate codes.

The checkout recalculates eligible methods after an address, cart, discount, or method update. Carrier-defined safe fields are retained during refresh, validated again at confirmation, and stored with the order for fulfillment.
