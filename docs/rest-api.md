# REST API

This plugin's REST surface exists for one consumer: an external system — in practice a
point-of-sale app — that syncs the shop's organizations to work from offline and places orders on
members' behalf. It has two halves, split by whether WooCommerce already has the noun:

| Surface | What it is | Route |
|---|---|---|
| Organization snapshot | **New route.** WooCommerce has no representation of organizations, members or locations — `/wc/v3/customers` returns WordPress users. | `GET /wp-json/wc-woap/v1/organizations` |
| Orders | **WooCommerce's own route, extended.** Same line items, taxes, coupons and stock handling as any `/wc/v3` order — plus the organization rules, five extra read fields, one extra write field and one list filter. | `/wp-json/wc/v3/orders` |

There is deliberately no `woap/v1` orders route. Recreating order creation would re-implement line
items, tax and stock, and split "every order carries one organization" across two code paths.

Everything below is implemented in `includes/Rest/` — `OrganizationsController` for the snapshot,
`Orders` for the order rules — and asserted by `tests/RestApiTest.php` and
`tests/RestOrdersTest.php`. When this document and the code disagree, the code and its tests win;
fix the document.

## Authentication

Both halves authenticate with an ordinary **WooCommerce REST API key** (WooCommerce → Settings →
Advanced → REST API). Whatever already works for `/wc/v3/orders` works for the snapshot route
unchanged: HTTP Basic auth with the consumer key and secret over HTTPS, or OAuth 1.0a over plain
HTTP.

The snapshot route lives under `wc-woap/v1`, and the `wc-` prefix is load-bearing:
`WC_REST_Authentication` only reads consumer keys for request URIs containing `wc/` or `wc-`. It is
the prefix WooCommerce documents for third-party plugins that want its authentication, and
`RestApiTest::testTheNamespaceKeepsWooCommercesAuthenticationPrefix` pins it, because renaming the
namespace would break nothing in the test suite and every till in the field.

The key must belong to a user holding `manage_woocommerce`. The plugin's own capabilities
(`woap_manage_organization` and friends) deliberately do **not** open the snapshot: they are
granted from a membership and answer what a member may do to *their own* organization, and the
answer to that is never "read every organization on the site".

Error handling throughout: **key on the `code` field, never on `message`.** Messages are
translated into the site's locale and follow the site's organization mode — the same refusal reads
"Branch" on one shop and "Campus" on another.

---

## The organization snapshot

```
GET /wp-json/wc-woap/v1/organizations
```

Returns every organization, each with its members and its locations embedded, for a device that
syncs on an interval and then works offline. Search is deliberately absent — the device holds the
whole set and searches locally.

### It is a full snapshot, not a delta — by design

Do not look for a `modified_after` parameter; one cannot be honoured truthfully. Only the
organization row carries a modification date: members and locations record their creation only, and
every delete in this plugin is a hard delete with no tombstone. A delta keyed on a timestamp would
never report that an employee left or that a location was removed, and the device would go on
offering a delivery address the organization has abandoned. A snapshot answers deletions by
omission, which is the one thing the schema can say truthfully.

**Sync by replacing, not merging**: after fetching all pages, anything the device holds that the
snapshot did not contain is gone — treat it as deleted.

### Polling cheaply

Every page carries an `ETag`. Send it back as `If-None-Match` on the next poll and an unchanged
page answers `304 Not Modified` with no body, so an interval sync costs a hash comparison rather
than a payload. The ETag covers the whole page — members and locations included — so a change
*below* the organization row still changes it, even though those rows carry no dates of their own.

```bash
curl -u ck_xxx:cs_xxx "https://shop.example/wp-json/wc-woap/v1/organizations?per_page=100" -i
# … ETag: "9748c06574e3324238719881e45c601f"

curl -u ck_xxx:cs_xxx "https://shop.example/wp-json/wc-woap/v1/organizations?per_page=100" \
  -H 'If-None-Match: "9748c06574e3324238719881e45c601f"'
# HTTP/1.1 304 Not Modified
```

### Parameters

| Parameter | Type | Default | Notes |
|---|---|---|---|
| `page` | integer ≥ 1 | `1` | A page beyond the end is a `400`, not an empty list — an empty list would look like a finished sync. |
| `per_page` | integer 1–200 | `50` | Above 200 is a `400`, not a silent clamp — a device handed 200 of its requested 10,000 would believe its sync complete. |
| `status` | string | all | One of `pending`, `active`, `suspended`, `rejected`. Anything else is a `400`. For a sync, omit it: the counter needs to say "this account is suspended", not "unknown customer". |

Responses carry `X-WP-Total` and `X-WP-TotalPages`. Pages are ordered by **ID**, which is stable
under renames — ordered by name, a rename landing between two requests could move an organization
across the page boundary and drop it from the sync entirely.

### Response

```json
[
  {
    "id": 12,
    "name": "Acme GmbH",
    "status": "active",
    "allow_custom_shipping": true,
    "billing": {
      "first_name": "Ada",
      "last_name": "Byron",
      "company": "Acme GmbH",
      "address_1": "1 Hauptstrasse",
      "address_2": "",
      "city": "Berlin",
      "state": "",
      "postcode": "10115",
      "country": "DE",
      "email": "buy@acme.example",
      "phone": "+49 30 000000"
    },
    "members": [
      {
        "member_id": 7,
        "user_id": 45,
        "name": "Grace Hopper",
        "email": "grace@acme.example",
        "role": "admin",
        "status": "active",
        "can_place_orders": true,
        "location_access": "all"
      }
    ],
    "locations": [
      {
        "id": 3,
        "name": "Warehouse North",
        "is_default": true,
        "first_name": "Grace",
        "last_name": "Hopper",
        "company": "",
        "address_1": "9 Lagerweg",
        "address_2": "",
        "city": "Hamburg",
        "state": "",
        "postcode": "20095",
        "country": "DE",
        "phone": "+49 40 123456"
      }
    ],
    "date_modified_gmt": "2026-08-09 03:51:08"
  }
]
```

Field notes — the non-obvious ones:

- **`billing`** is the organization's centralised billing address, in WooCommerce's own field
  names. Every key is always present, empty or not, so a device can map it onto an order without
  conditionals. Display only: when the device *creates* an order, the server writes this address
  itself and discards whatever was posted (see below).
- **`members[].user_id`** is the WordPress user ID — the value to send as `customer_id` when
  creating an order for that member. `member_id` identifies the membership row and is not used in
  any order call.
- **`members[].role`** is the *organization* role, `admin` or `member` — not a WordPress role
  name.
- **`members[].can_place_orders`** is the resolved answer to "may this person buy right now?",
  computed server-side from membership status, organization status and the member's capability
  set. **Use it, do not re-derive it.** The inputs are deliberately not exposed: the underlying
  capability store is a diff against role defaults and is meaningless — actively misleading —
  without the defaults table. This flag is a UX hint for the offline device; the server re-checks
  at order creation regardless, so a stale snapshot degrades to a clean refusal, not a wrong
  order.
- **`members[].location_access`** is either the string `"all"` or an array of location IDs. It is
  never an empty array — in storage an empty access list means *unrestricted*, and handing a
  device `[]` would tell it the opposite of the truth.
- **`locations[]`** are WooCommerce shipping addresses column for column, plus `name` (the label a
  location is chosen by) and `is_default`. A blank `company` is normal — the server fills in the
  organization's name when it lands on an order.
- **`date_modified_gmt`** moves only when the organization *row* changes. Members and locations
  carry no such date — which is exactly why this route serves snapshots. Do not build a delta on
  it.
- **Absent on purpose:** the organization's `tax_id` (the one regulated identifier in the table,
  kept off pocketable devices) and members' capability overrides (see `can_place_orders`).
  Memberships whose WordPress user has been deleted are omitted entirely.

### Errors

| Status | Code | Meaning |
|---|---|---|
| 401 / 403 | `woap_rest_forbidden` | No credentials / credentials without `manage_woocommerce`. |
| 400 | `woap_rest_invalid_page_number` | `page` beyond the last page. |
| 400 | `rest_invalid_param` | Bad `status`, `per_page` out of range — WordPress's own validation. |

---

## Orders

Orders are created, read, updated and listed through WooCommerce's standard route — [its
documentation](https://woocommerce.github.io/woocommerce-rest-api-docs/#orders) applies in full.
This plugin adds the organization layer. Nothing below requires new client plumbing: same
endpoints, same auth, a handful of extra fields.

### Creating an order for a member

```bash
curl -u ck_xxx:cs_xxx -X POST "https://shop.example/wp-json/wc/v3/orders" \
  -H "Content-Type: application/json" \
  -d '{
    "customer_id": 45,
    "woap_location_id": 3,
    "line_items": [ { "product_id": 101, "quantity": 2 } ],
    "shipping_lines": [ { "method_id": "flat_rate", "method_title": "Flat rate", "total": "0" } ],
    "set_paid": true
  }'
```

Send the member's **`user_id` from the snapshot as `customer_id`**. That is what makes the order
the member's — it appears in their *My orders*, in their organization's order list, and in the
customer emails. The API key identifies the till; the customer identifies who the order is for.

Rules applied on creation, when the customer is a member — the same rules as both checkouts,
applied to the **customer**, never to the API key:

- **The purchase gate runs against the member.** Inactive membership, non-active organization or a
  revoked `woap_place_orders` refuses with `403 woap_rest_cannot_purchase` and the human-readable
  reason. No order is created on any refusal.
- **Billing is not yours to set.** The organization's address is written over whatever the request
  carried — posted billing is discarded, not validated. Omit the `billing` block entirely; sending
  one changes nothing.
- **Shipping is a location ID, not an address.** `woap_location_id` (the same field name the
  checkout posts) is resolved scoped to the member's organization *and* their access list. An ID
  from another organization or outside the member's list refuses with
  `400 woap_rest_shipping_destination` — the resolution does not loosen because the caller holds
  the shop's key, because the till acts *for* the member and must be refused exactly where the
  member would be. A location with a blank `company` ships under the organization's name.
- **A one-off address needs the organization's permission.** When `allow_custom_shipping` is true
  in the snapshot, omit `woap_location_id` and post a `shipping` block; it is used as-is. When it
  is false, an order that needs a shipping address must name a location.
- **An order with no shipping needs no location.** Whether an order "needs a shipping address" is
  WooCommerce's own answer and reads the **`shipping_lines`**, not the products — a walk-out sale
  posts no shipping lines and is billed and stamped like every other order, with `woap_location_id`
  recorded as `0`.
- **A customer with no membership is left alone.** No gate, no billing overwrite, no stamp — the
  same "no organization, no opinion" rule the rest of the plugin follows. On a strict organization
  shop the only such customers are the shop's own staff.

### Reading orders

Every order response — single or list — carries five read-only fields:

| Field | Type | Meaning |
|---|---|---|
| `woap_organization_id` | integer | Organization the order belongs to. `0` when it has none (pre-plugin or non-member orders). |
| `woap_organization_name` | string | The organization's name **as it was when the order was placed** — a snapshot that survives renames and deletions. |
| `woap_location_id` | integer | Delivery location. `0` means a one-off address was used, or no delivery. |
| `woap_location_name` | string | The location's name at order time — same snapshot rule. |
| `woap_member_user_id` | integer | The member the order was placed for. Recorded separately from `customer_id` because an admin can reassign an order's customer later; this field says what happened. |

And the list accepts one extra filter:

```
GET /wp-json/wc/v3/orders?woap_organization=12
```

— an order history for one organization without paging the whole shop through the device. Combine
freely with WooCommerce's own parameters (`status`, `after`, `per_page`, …).

### Updating an order

Updates run **none** of the creation rules. A `PUT` to an existing order is shop staff editing a
record they own — the same act as editing it in wp-admin — and re-running the gate against an old
order would refuse edits to history whenever a member has since left. Status changes, refund flows
and everything else WooCommerce supports work untouched.

The one organization-shaped edit an update accepts is re-pointing delivery:

```bash
curl -u ck_xxx:cs_xxx -X PUT "https://shop.example/wp-json/wc/v3/orders/8123" \
  -H "Content-Type: application/json" \
  -d '{ "woap_location_id": 5 }'
```

The location is resolved against the **organization on the order** — not the member's access list,
which governs what a member may *choose*; this is the shop redirecting a parcel it is fulfilling.
Cross-organization is still the line: another organization's location ID refuses with
`400 woap_rest_shipping_destination`, and a non-organization order refuses with
`400 woap_rest_not_an_organization_order`. The shipping address and the location stamp are
rewritten together; the member stamp is untouched, because moving a parcel does not change who
ordered it.

### Errors

| Status | Code | When |
|---|---|---|
| 403 | `woap_rest_cannot_purchase` | Create: the customer may not buy — inactive membership, pending/suspended/rejected organization, or revoked `woap_place_orders`. `message` carries the reason a person can act on ("… is still awaiting approval …"). |
| 400 | `woap_rest_shipping_destination` | Create: unknown location, wrong organization, outside the member's access list, an incomplete location address (named in the message), or no location where one is required. Update: location not this order's organization's. |
| 400 | `woap_rest_not_an_organization_order` | Update: `woap_location_id` sent for an order that belongs to no organization. |

WooCommerce's own errors (`woocommerce_rest_invalid_customer_id`, product/stock errors, …) pass
through unchanged. On any error the order does not exist — the checks run before the first save.

---

## A till's flow, end to end

1. **Sync** — page through `GET /wc-woap/v1/organizations` with `If-None-Match` per page; replace
   the local set wholesale. Repeat on an interval.
2. **At the counter** — find the organization and member locally. Show `status` and
   `can_place_orders` so a refusal is explained before ringing anything up. Offer the member's
   locations: all of them when `location_access` is `"all"`, otherwise only the listed IDs; a
   one-off address only when `allow_custom_shipping`.
3. **Place** — `POST /wc/v3/orders` with `customer_id` = member's `user_id` and
   `woap_location_id`, or no location for a walk-out sale. Handle the two `woap_*` error codes;
   everything else is standard WooCommerce.
4. **History** — `GET /wc/v3/orders?woap_organization=<id>` when the customer asks what their
   organization has ordered.

The device never enforces anything. Every rule above is applied server-side on every request, so a
stale snapshot, a modified client or a hand-written request all degrade to the same clean refusal.
