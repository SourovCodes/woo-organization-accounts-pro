# REST API

This plugin's REST surface serves two consumers, and it is worth knowing which one a route was
written for:

- **A till** — a point-of-sale app that syncs the shop's organizations to work from offline and
  places orders on members' behalf. It reads; it writes only orders.
- **A back office** — an app where somebody reviews a registration and approves it, corrects a
  billing address, adds a branch, puts an employee on an account. It writes.

Split the other way, by whether WooCommerce already has the noun:

| Surface | What it is | Route |
|---|---|---|
| Organization snapshot | **New route.** WooCommerce has no representation of organizations, members or locations — `/wc/v3/customers` returns WordPress users. | `GET /wp-json/wc-woap/v1/organizations` |
| Organizations, branches, employees | **New routes.** Create and edit the same records, and approve or suspend an account. | `/wp-json/wc-woap/v1/organizations/…` |
| Address forms | **New route.** WooCommerce's per-country address field definitions, serialised so the app renders the same form the checkout would. | `GET /wp-json/wc-woap/v1/address-form` |
| Orders | **WooCommerce's own route, extended.** Same line items, taxes, coupons and stock handling as any `/wc/v3` order — plus the organization rules, five extra read fields, one extra write field and one list filter. | `/wp-json/wc/v3/orders` |

There is deliberately no `woap/v1` orders route. Recreating order creation would re-implement line
items, tax and stock, and split "every order carries one organization" across two code paths.

Everything below is implemented in `includes/Rest/` — `OrganizationsController` for organizations,
`LocationsController` and `MembersController` for the sub-resources, `Orders` for the order rules —
and asserted by `tests/RestApiTest.php`, `tests/RestWritesTest.php` and `tests/RestOrdersTest.php`.
When this document and the code disagree, the code and its tests win; fix the document.

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

The key must belong to a user holding `manage_woocommerce`, on every route in this namespace —
reads and writes alike. The plugin's own capabilities (`woap_manage_organization` and friends)
deliberately do **not** open any of it: they are granted from a membership and answer what a member
may do to *their own* organization, and the answer to that is never "read every organization on the
site", still less "approve one". An organization admin's key is refused with `403` here even for
their own organization; their surface is the account screens on the site itself.

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
    "billing_formatted": "Acme GmbH\nAda Byron\n1 Hauptstrasse\n10115 Berlin",
    "members": [
      {
        "member_id": 7,
        "user_id": 45,
        "name": "Grace Hopper",
        "first_name": "Grace",
        "last_name": "Hopper",
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
        "formatted": "Grace Hopper\nAcme GmbH\n9 Lagerweg\n20095 Hamburg",
        "first_name": "Grace",
        "last_name": "Hopper",
        "company": "Acme GmbH",
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
- **`members[].name` is the display name; `first_name` and `last_name` are the fields you edit.**
  Show `name` — it is what every screen on the shop prints, and it is what a shop that has written
  something else there wants shown. Fill a form from the other two.
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
  location is chosen by) and `is_default`. `company` is served but never asked for — see
  [no form ever asks for a company](#no-form-ever-asks-for-a-company).
- **`billing_formatted` and `locations[].formatted`** are the addresses as WooCommerce prints them
  **for their country** — a German address puts the postcode before the city, an American one
  after, and the formatter knows every variant. Newline-separated. Show these; do not assemble an
  envelope layout from the fields yourself.
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

## The address forms

```
GET /wp-json/wc-woap/v1/address-form
```

WooCommerce handles addresses differently per country — which fields exist, which are required,
what they are called, whether the state is a free text or a fixed list — and the app must not
hand-write an address form, because a hand-written one is wrong in a different way in every
country. This route serialises **WooCommerce's own field definitions**, per country the shop ships
to, with the shop's own checkout-field customisations already applied. Render from it and the till
shows exactly the form the shop's checkout would.

**Two shapes are served, and they are not interchangeable.** `forms` is a delivery address — a
one-off address on an order, or a location. `billing_forms` is the address an organization is
billed at. Use the one that matches what is being edited.

The difference is required-ness, and it is deliberate. On a **delivery** address two fields are
**not** WooCommerce's answer: `last_name` is never required, because such an address belongs to a
place at least as often as to a person and "Warehouse North" has no surname — put the place name in
`first_name` and leave `last_name` empty — and `phone` is never required either, whatever the
shop's checkout phone setting says, because that setting is a rule about the person buying. Both
are still validated when filled in. **Billing keeps WooCommerce's own rules for both.** Render a
billing form from `forms` and the screen marks a surname optional that the write path then
requires: a refusal at the counter, over a rule the operator was told did not apply.

Render required-ness from this response rather than from WooCommerce's published defaults, or the
till will refuse an address the shop's own screens accept.

Sync it like the snapshot: the whole set in one response, `ETag`/`If-None-Match` for cheap
revalidation. It changes when WooCommerce or the shop's settings change, which is rarely.

```json
{
  "default_country": "CH",
  "countries": { "CH": "Switzerland", "LI": "Liechtenstein" },
  "billing_countries": { "CH": "Switzerland", "LI": "Liechtenstein", "DE": "Germany" },
  "forms": {
    "CH": [
      { "name": "first_name", "label": "First name",       "required": true,  "hidden": false, "type": "text" },
      { "name": "last_name",  "label": "Last name",        "required": false, "hidden": false, "type": "text" },
      { "name": "country",    "label": "Country / Region", "required": true,  "hidden": false, "type": "country" },
      { "name": "address_1",  "label": "Street address",   "required": true,  "hidden": false, "type": "text" },
      { "name": "postcode",   "label": "Postcode",         "required": true,  "hidden": false, "type": "text" },
      { "name": "city",       "label": "Town / City",      "required": true,  "hidden": false, "type": "text" },
      { "name": "state",      "label": "Canton",           "required": false, "hidden": false, "type": "state",
        "options": { "AG": "Aargau", "BE": "Bern", "…": "…" } },
      { "name": "phone",      "label": "Phone",            "required": false, "hidden": false, "type": "tel" }
    ],
    "LI": [ "…" ]
  },
  "billing_forms": {
    "CH": [
      { "name": "first_name", "label": "First name",       "required": true,  "hidden": false, "type": "text" },
      { "name": "last_name",  "label": "Last name",        "required": true,  "hidden": false, "type": "text" },
      { "name": "country",    "label": "Country / Region", "required": true,  "hidden": false, "type": "country" },
      { "name": "address_1",  "label": "Street address",   "required": true,  "hidden": false, "type": "text" },
      { "name": "postcode",   "label": "Postcode",         "required": true,  "hidden": false, "type": "text" },
      { "name": "city",       "label": "Town / City",      "required": true,  "hidden": false, "type": "text" },
      { "name": "state",      "label": "Canton",           "required": false, "hidden": false, "type": "state",
        "options": { "AG": "Aargau", "BE": "Bern", "…": "…" } },
      { "name": "email",      "label": "Email address",    "required": true,  "hidden": false, "type": "email" },
      { "name": "phone",      "label": "Phone",            "required": false, "hidden": false, "type": "tel" }
    ],
    "LI": [ "…" ]
  }
}
```

Note `last_name` above: optional under `forms`, required under `billing_forms`, same country, same
shop. That is the whole reason both are served.

How to render it:

- **Fields are in display order** — WooCommerce's order for that country, already sorted.
- **`required`, `label` and `hidden` are per country.** The same field is "Canton" and a 26-entry
  list in Switzerland, "State" and a 50-entry list in the US, and free text elsewhere. Skip
  `hidden` fields entirely.
- **`options` appears only on the `state` field and only where the country has a list.** Present:
  render a picker and submit the *code* (the key). Absent: free text is correct — that is what the
  checkout renders too.
- **Two country lists, one per shape.** `countries` is the shop's ship-to list and keys `forms`;
  `billing_countries` is its sell-to list and keys `billing_forms`. WooCommerce keeps them
  separately and they genuinely differ — a shop sells to more places than it ships to as soon as one
  customer's invoices go somewhere its couriers do not. Offer the matching list in each picker, or a
  billing country the shop's own admin can save becomes unselectable. `default_country` is the
  shop's base, for preselecting.
- **`billing_forms` carries an `email`**, which `forms` does not — a delivery address has no email
  and an organization's billing address does. If the screen asks for it in a field of its own, drop
  it from the rendered form rather than showing two boxes for one answer.
- Submit the values under the same `name`s — a delivery form into the order request's `shipping`
  block, a billing form into the organization request's `billing` block.

Do not duplicate the validation client-side beyond required-marking: the server validates every
one-off address with the same rules anyway (below), and its answers are authoritative.

### No form ever asks for a company

`company` is absent from every form this route serves, and from the billing fields the account
screens render. It is not a shop setting you can switch back on — an organization *is* the company,
so a second field asking for the name again is a second answer to a question already answered.

It is still stored, still served, and still lands on orders. It is derived rather than typed:

- **An organization's `billing_company` is its `name`**, written on every save. Rename the account
  and its next invoice follows, because the column is derived rather than copied.
- **A location's `company` is that same name**, filled in at save time and again when the order is
  built, so what the screen shows is what the courier gets.

Send `company` anyway and it is discarded — a submitted address is intersected with the fields the
country actually has, and this is not one of them. There is no need to strip it client-side; there
is also no point sending it.

---

## Managing accounts from a back office

Everything a shop does to an organization from wp-admin, an app can do here. Same records, same
rules, same validation as the plugin's own screens — these routes call the identical repositories
and validators, so an address this route accepts is one the checkout accepts, and an account this
route approves sends the same email wp-admin would.

```
GET    /wc-woap/v1/organizations/<id>
POST   /wc-woap/v1/organizations
PATCH  /wc-woap/v1/organizations/<id>
POST   /wc-woap/v1/organizations/<id>/status

GET    /wc-woap/v1/organizations/<id>/locations
POST   /wc-woap/v1/organizations/<id>/locations
GET    /wc-woap/v1/organizations/<id>/locations/<location_id>
PATCH  /wc-woap/v1/organizations/<id>/locations/<location_id>
DELETE /wc-woap/v1/organizations/<id>/locations/<location_id>

GET    /wc-woap/v1/organizations/<id>/members
POST   /wc-woap/v1/organizations/<id>/members
GET    /wc-woap/v1/organizations/<id>/members/<member_id>
PATCH  /wc-woap/v1/organizations/<id>/members/<member_id>
DELETE /wc-woap/v1/organizations/<id>/members/<member_id>
```

Three things hold across all of them:

- **Sub-resources are scoped to the organization in the path.** A location or member ID belonging
  to another organization is a `404`, not a `403` and not a silent success. Ask for the right
  parent.
- **A validation refusal names the fields.** The body carries WordPress's own shape —
  `data.params`, a map of field path to reason — so a form can mark the offending inputs instead of
  showing one banner over a fourteen-field address. Paths match what you sent:
  `billing.postcode` on an organization, `postcode` on a location.
- **Nothing here is deleted that orders depend on.** There is no route to delete an organization:
  that cascades members, locations and invitations and resets everybody's WordPress role, and it is
  a wp-admin act. Use `suspended` or `rejected`, both reversible.

### Reviewing and approving

The review queue is the snapshot filtered by status:

```bash
curl -u ck_xxx:cs_xxx "https://shop.example/wp-json/wc-woap/v1/organizations?status=pending"
```

Approving is its own route, deliberately not a field on the edit:

```bash
curl -u ck_xxx:cs_xxx -X POST \
  "https://shop.example/wp-json/wc-woap/v1/organizations/12/status" \
  -H "Content-Type: application/json" \
  -d '{ "status": "active" }'
```

```json
{ "changed": true, "organization": { "id": 12, "status": "active", "…": "…" } }
```

- **`status` is one of `pending`, `active`, `suspended`, `rejected`.** Only `active` can buy.
- **This is what sends the mail.** The approval and rejection emails, and the sign-in gate when the
  shop runs `require_approval_to_sign_in`, all hang off the status change — which is why it is a
  route of its own and why `PATCH`ing an organization with a `status` in the body is refused with
  `400 woap_rest_status_has_its_own_route` rather than quietly applied. A client that fetched an
  organization, fixed a typo and sent the whole object back would otherwise approve it by accident.
- **Asking for the status it already holds is a success, not an error**: `changed` comes back
  `false` and nothing is sent. Two people working the same queue, or one person double-tapping, do
  not produce two approval emails.

### Creating and editing an organization

```bash
curl -u ck_xxx:cs_xxx -X POST "https://shop.example/wp-json/wc-woap/v1/organizations" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Bauer & Söhne GmbH",
    "tax_id": "DE123456789",
    "billing": {
      "first_name": "Ada", "last_name": "Byron",
      "address_1": "1 Hauptstrasse", "city": "Berlin",
      "postcode": "10115", "country": "DE",
      "email": "buy@bauer.example", "phone": "+49 30 000000"
    }
  }'
```

Answers `201` with the organization in the snapshot's own shape — the same payload `GET
/organizations/<id>` returns, so a client has one parser.

| Field | On create | On edit |
|---|---|---|
| `name` | required | optional; blank is refused |
| `tax_id` | optional, unless the shop sets *require tax ID* | same |
| `status` | optional, defaults to `pending` | **refused** — use the status route |
| `allow_custom_shipping` | optional, defaults to `true` | optional |
| `billing` | object, WooCommerce's billing field names | optional; merged onto what is stored |

- **A new organization starts `pending`** unless you say otherwise, so an account entered by staff
  goes through the same review as one that registered itself.
- **Nothing is emailed on create.** The shop's new-account mail belongs to somebody signing up; the
  approval mail comes from the status route.
- **An edit is partial**, and merges: send three billing fields and three change. But the *merged*
  address is then validated whole, because which fields an address needs depends on its country —
  changing only `country` would otherwise leave a US address with no state. The consequence worth
  knowing: editing the address of a record that was stored incomplete refuses until the missing
  field is supplied, naming it. An edit that says nothing about the address skips the check
  entirely, so such a record can still be renamed.
- **The address is validated exactly as the checkout validates one** — required fields for that
  country, postcode format, state from the country's list — and normalised the same way, so
  `"California"` is stored as `CA`. Build the form from [the address forms](#the-address-forms).
- Fields a country does not have are dropped rather than refused, the same as the shop's own forms:
  a `state` posted for a country with no states is not data.

### Branches

A location is a WooCommerce shipping address column for column, plus `name` (the label it is chosen
by at the checkout) and `is_default`. The address fields sit at the top level of the body, matching
how the snapshot reports them.

```bash
curl -u ck_xxx:cs_xxx -X POST \
  "https://shop.example/wp-json/wc-woap/v1/organizations/12/locations" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Warehouse North",
    "first_name": "Grace", "last_name": "Hopper",
    "address_1": "9 Lagerweg", "city": "Hamburg",
    "postcode": "20095", "country": "DE"
  }'
```

- **`name` is required; a surname and a phone are not** — even when the shop's checkout requires a
  phone of a buyer. A delivery address belongs to a place at least as often as to a person
  ("Warehouse North" has no surname), and a rule fair to apply to somebody typing at a checkout is
  not automatically fair applied retroactively to records the shop has already saved. Both are
  still validated when present.
- **`company` is the organization's name**, filled in here rather than asked for and stored rather
  than resolved later — a parcel with no company on the label is one nobody at a loading bay
  recognises. See [no form ever asks for a company](#no-form-ever-asks-for-a-company).
- **`is_default` is the location new orders start at.** Setting it on one clears it on the others.
- **Deleting takes the location out of every member's access list** as it goes. It is allowed even
  for the last one — the shop's own screens allow it — but the response says what that means:
  `organization_can_ship` comes back `false`, and an organization with no locations cannot check
  out at all.

```json
{ "deleted": true, "previous": { "…": "…" }, "organization_can_ship": false }
```

### Employees

Adding somebody happens two ways and the request says which, because they are not variants of one
act:

```bash
# Invite — the default. They set their own password and join by accepting.
curl -u ck_xxx:cs_xxx -X POST \
  "https://shop.example/wp-json/wc-woap/v1/organizations/12/members" \
  -H "Content-Type: application/json" \
  -d '{ "email": "karl@bauer.example", "role": "member" }'

# Create — staff entering an employee on the customer's behalf.
curl -u ck_xxx:cs_xxx -X POST \
  "https://shop.example/wp-json/wc-woap/v1/organizations/12/members" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "karl@bauer.example", "method": "create", "role": "member",
    "first_name": "Karl", "last_name": "Schmidt",
    "location_access": [ 3 ]
  }'
```

| | `invite` (default) | `create` |
|---|---|---|
| What exists afterwards | an invitation | the WordPress account and the membership |
| Response | `201` with the invitation | `201` with the member |
| Email | the invitation link | **none** |
| Password | they choose it | random, nobody holds it — they use the shop's lost-password form |
| May carry permissions | no | yes |

- **The invitation token is never in a response.** It exists in plaintext long enough to be put in
  one email; the row keeps only a SHA-256 digest. An invitation is bound to the address it was sent
  to, works once, and can be withdrawn from the account screens.
- **An invitation cannot carry `capabilities`, `location_access`, `status`, `first_name` or
  `last_name`** — they describe a membership, and no membership exists until acceptance. Sending
  them is `400 woap_rest_invitation_extras` rather than a silent drop, so a client is never left
  believing it restricted somebody when it did not. Set them afterwards with `PATCH`.
- **An address that already has a WordPress account is joined up, not duplicated** — and anybody
  who can manage the shop keeps their WordPress role, because a membership must not demote an
  administrator out of wp-admin.
- **Somebody who already belongs to an organization is never moved into another**:
  `409 woap_rest_already_member`, with `organization_id` and `member_id` in `data` so the app can
  show where they are. A person belongs to one organization at a time — the column is UNIQUE — and
  every order they have placed is scoped by that membership row.

Editing a membership:

```bash
curl -u ck_xxx:cs_xxx -X PATCH \
  "https://shop.example/wp-json/wc-woap/v1/organizations/12/members/7" \
  -H "Content-Type: application/json" \
  -d '{ "role": "admin", "capabilities": "role_default", "location_access": "all" }'
```

| Field | Values |
|---|---|
| `first_name` | their first name, on the WordPress account behind the membership |
| `last_name` | their surname |
| `email` | the address they sign in with and the shop writes to |
| `role` | `admin` or `member` — the *organization* role, not a WordPress role name |
| `status` | `active` or `inactive` |
| `capabilities` | `"role_default"`, or an object of capability → boolean |
| `location_access` | `"all"`, or a non-empty array of location IDs of this organization |

- **An edit is partial, and a field you do not send is not touched.** There is no way to say "blank
  the surname" other than sending `"last_name": ""`.
- **The name and the address live on the WordPress account, not on the membership**, which is why
  they are edited here rather than through `/wp/v2/users`: one route, one set of rules, one answer
  about a person. Four things follow, and each is asserted by `RestWritesTest`:
  - **The login name never changes.** An account created from an address keeps that address as its
    WordPress `user_login` for good — WordPress does not allow a rename. It is of no consequence:
    WordPress signs somebody in by either their login or their email address, so after this edit
    they use the new one.
  - **`name` follows the names you send**, because the display name is what every screen on the
    shop prints and a rename nothing displayed would be no rename at all. A display name a shop has
    set by hand — anything that is not the old name or the old address — is left exactly as it is.
  - **WordPress emails the *old* address** to say it was changed, as it does for any account. That
    is core's notice, not one of this plugin's four; a shop that does not want it filters
    `send_email_change_email`. Nothing else is sent, and the member is not signed out.
  - **An address belongs to one account.** Moving somebody onto one that already exists is
    `409`, never a merge: `woap_rest_already_member` when it belongs to another organization (with
    `organization_id` and `member_id` in `data`, as when adding somebody), and
    `woap_rest_email_taken` with `user_id` when it is an account with no membership. To join two
    accounts, remove the member and add the other address instead.
- **Nothing is written when an edit is refused.** The account is changed before the membership row
  for exactly this reason: a refusal a client will actually meet — an address that is somebody
  else's — happens while both are still untouched, rather than leaving somebody promoted to admin
  by a request that failed.
- **Permissions are a diff against the role, and this route does the arithmetic.** Send what should
  be *true* of the member; anything you do not mention follows the role, and only what differs is
  stored. So `{"role": "admin", "capabilities": "role_default"}` produces an admin with an admin's
  permissions, and `{"capabilities": {"woap_place_orders": false}}` produces a member who may do
  everything their role allows except buy. **Do not send back the map you read for the previous
  role** as an absolute set — it would pin the member to permissions their new role has moved away
  from. The capabilities are `woap_manage_organization`, `woap_manage_billing`,
  `woap_manage_locations`, `woap_manage_members`, `woap_invite_members`,
  `woap_view_organization_orders`, `woap_place_orders`; anything else is a `400`.
- **`location_access` has no way to say "none".** In storage an empty list means *every* location,
  so `[]` would silently grant the opposite of what it asks; it is refused. Somebody who should not
  be ordering is `"status": "inactive"`.
- **The member routes report permissions; the snapshot does not.** `GET …/members` and
  `GET …/members/<id>` carry `capabilities` (the resolved map, role defaults with overrides applied)
  and `capabilities_follow_role`. The snapshot deliberately omits both: a till syncing a copy it
  carries on a counter has no use for the permission configuration of every employee on the shop.
- **An organization must keep one active admin.** The last one cannot be demoted or deactivated
  (`400`, with `role` in `data.params`) or removed (`409 woap_rest_last_admin`). Promote somebody
  else first.
- **Removing somebody keeps their login** and moves it to WooCommerce's `customer` role. Deleting a
  WordPress account because somebody changed jobs is not this plugin's decision; with no membership
  row they simply cannot buy on the account any more.

### Errors

| Status | Code | When |
|---|---|---|
| 401 / 403 | `woap_rest_forbidden` | No credentials, or credentials without `manage_woocommerce`. |
| 404 | `woap_rest_organization_not_found` | No such organization. |
| 404 | `woap_rest_location_not_found` / `woap_rest_member_not_found` | Not that organization's — check the parent in the path. |
| 400 | `woap_rest_invalid_organization` / `woap_rest_invalid_location` / `woap_rest_invalid_member` | Validation. `data.params` maps field path → reason. |
| 400 | `woap_rest_status_has_its_own_route` | A `status` in the body of an organization edit. |
| 400 | `woap_rest_invitation_extras` | Membership fields on an invitation. |
| 409 | `woap_rest_already_member` | That address belongs to an organization already. |
| 409 | `woap_rest_email_taken` | Editing a member onto an address that has a WordPress account of its own. `data.user_id` is the account holding it. |
| 409 | `woap_rest_last_admin` | Removing the last active organization admin. |
| 400 | `rest_invalid_param` / `rest_missing_callback_param` | WordPress's own validation: unknown `role`, `status` or `method`, a missing required `name` or `email`. |
| 500 | `woap_rest_not_saved` | The write failed at the database. Nothing partial is left behind. |

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
  member would be. Every location ships under the organization's name, which is the only company
  name there is.
- **A one-off address needs the organization's permission — and is validated per country.** When
  `allow_custom_shipping` is true in the snapshot, omit `woap_location_id` and post a `shipping`
  block, built from [the address forms](#the-address-forms). It is then held to **the same rules
  the checkout applies to a typed address**: the fields the country actually requires, a postcode
  valid for that country, a state from the country's list. A refusal is
  `400 woap_rest_shipping_address` and no order exists. Accepted values are normalised the way the
  checkout normalises them — the postcode formatted, a state name like "California" stored as its
  code — so what lands on the order is what WooCommerce would have stored. When
  `allow_custom_shipping` is false, an order that needs a shipping address must name a location.
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
| 400 | `woap_rest_shipping_address` | Create: a one-off address the shop's checkout would refuse — a required field for that country empty, an invalid postcode, a state not on the country's list. The message names each rejected field. |
| 400 | `woap_rest_not_an_organization_order` | Update: `woap_location_id` sent for an order that belongs to no organization. |

WooCommerce's own errors (`woocommerce_rest_invalid_customer_id`, product/stock errors, …) pass
through unchanged. On any error the order does not exist — the checks run before the first save.

---

## A till's flow, end to end

1. **Sync** — page through `GET /wc-woap/v1/organizations` with `If-None-Match` per page; replace
   the local set wholesale. Fetch `GET /wc-woap/v1/address-form` the same way. Repeat on an
   interval.
2. **At the counter** — find the organization and member locally. Show `status` and
   `can_place_orders` so a refusal is explained before ringing anything up. Offer the member's
   locations: all of them when `location_access` is `"all"`, otherwise only the listed IDs; a
   one-off address only when `allow_custom_shipping`, entered through the country's own form from
   the address-form sync.
3. **Place** — `POST /wc/v3/orders` with `customer_id` = member's `user_id` and
   `woap_location_id`, or no location for a walk-out sale. Handle the two `woap_*` error codes;
   everything else is standard WooCommerce.
4. **History** — `GET /wc/v3/orders?woap_organization=<id>` when the customer asks what their
   organization has ordered.

The device never enforces anything. Every rule above is applied server-side on every request, so a
stale snapshot, a modified client or a hand-written request all degrade to the same clean refusal.

---

## A back office's flow, end to end

1. **The queue** — `GET /wc-woap/v1/organizations?status=pending`. Each entry already carries its
   members and locations, so the review screen needs no further calls.
2. **Review** — show `billing_formatted` rather than assembling an address, and `tax_id` is
   deliberately absent from the snapshot: fetch the one organization with
   `GET /wc-woap/v1/organizations/<id>` if the reviewer needs it.
3. **Decide** — `POST /organizations/<id>/status` with `active` or `rejected`. That is what emails
   the customer. `changed: false` means somebody else got there first.
4. **Correct** — `PATCH /organizations/<id>` for the name or the billing address, building the form
   from `GET /wc-woap/v1/address-form`. Mark the fields named in `data.params` on a refusal.
5. **Branches and people** — `POST …/locations` and `POST …/members`, the latter as an invitation
   unless the shop is entering the account on somebody's behalf. Check `organization_can_ship` after
   deleting a location: an organization with none cannot check out.

The same rule as the till holds here. The app enforces nothing — every refusal above is decided
server-side, by the same code the shop's own screens run.
