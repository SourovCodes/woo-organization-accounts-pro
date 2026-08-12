# WooCommerce Organization Accounts Pro

A WooCommerce extension that turns the shop into a strict **organization-based B2B system**.
Customers are organizations, not individuals: every account belongs to one, billing is centralised
on the organization, shipping goes to the organization's locations, and there is no guest checkout.

- Text domain / slug: `woo-organization-accounts-pro`
- PHP namespace: `WooOrgAccounts\` (PSR-4 → `includes/`)
- Global prefix: `woap` / `woo_org_accounts` — constants, hooks, options, meta keys and table names.
  WPCS rejects prefixes shorter than four characters, so `wo` and `woa` are not usable.

## Minimum requirements

The plugin deliberately targets the current release of everything. There is no
backwards-compatibility budget: do not add shims, polyfills or version branches for older
WordPress, WooCommerce or PHP.

| Requirement | Floor | Enforced by |
|---|---|---|
| WordPress | 7.0 (current latest) | `Requires at least` header; WordPress blocks activation below it |
| PHP | 8.2 | `Requires PHP` header, `composer.json`, PHPCompatibilityWP `testVersion` |
| WooCommerce | 11.0 (current latest) | `Requires Plugins` header plus a runtime `version_compare` check |
| HPOS | **Required, not merely supported** | Runtime check; the plugin refuses to boot without it |
| Woodmart theme | 8.5, as the active theme or its parent | Runtime check; the plugin refuses to boot without it |

**High-Performance Order Storage is a hard requirement.** Declaring compatibility is not enough:
`bootstrap()` calls `OrderUtil::custom_orders_table_usage_is_enabled()` and, when HPOS is off,
registers an admin notice and returns without initialising. Every order this plugin writes carries
the organization it belongs to, and every organization order list reads it back; against the legacy
post-based store those queries would silently scope to the wrong data rather than fail loudly.

Because HPOS is guaranteed, order code uses the CRUD APIs directly and never carries a legacy
fallback. When you bump these floors, update the plugin header, `WOAP_MIN_WC_VERSION`,
`composer.json`, and the `testVersion` / `minimum_wp_version` values in `phpcs.xml.dist` together.

**Woodmart is the theme this plugin is built for**, not one it happens to work with. Everything it
renders on the frontend assumes Woodmart's markup and design tokens, so against another theme the
result is a screen styled by nothing rather than a screen styled differently. WordPress has no
`Requires Theme` header, so `is_theme_supported()` is a runtime check like the HPOS one.

- **It reads `get_template()`, never `get_stylesheet()`.** A Woodmart child theme reports `woodmart`
  from the former, and the requirement must not push a site off its own child theme.
- **It runs on `plugins_loaded`, before the theme has loaded**, so it cannot read `WOODMART_VERSION`
  or any other constant from `functions.php` — those do not exist yet. The template slug and the
  theme headers are both readable that early.
- **`woap_theme_supported` is the escape hatch.** Woodmart is commercial and cannot be installed
  into the WordPress test library or into CI, and some sites run it under a renamed fork.
  `tests/bootstrap.php` filters it to true before the plugin loads.
- **Refusing to boot re-opens the shop.** With the plugin inert, nothing filters
  `pre_option_woocommerce_enable_guest_checkout`, so guest checkout and WooCommerce's own
  registration come back. That is true of every gate here, but a theme is the one most likely to be
  switched by somebody who does not know it.

## Environment

Development runs against a **Local by WP Engine** site called *TestShop*, which this directory is
already symlinked into:

| | |
|---|---|
| Site root | `~/Local Sites/testshop/app/public` |
| URL | http://testshop.local |
| Stack | WordPress 7.0.x, WooCommerce 11.0.0, PHP 8.2.29, MySQL 8.4.0 |
| Plugin path in site | `wp-content/plugins/woo-organization-accounts-pro` → symlink to this repo |

**A bare `wp` command does not work.** wp-config.php sets `DB_HOST` to `localhost` and Local serves
MySQL over a socket the Homebrew PHP cannot see, so wp-cli fails with *Error establishing a database
connection*. Always use the wrapper, which resolves the site, the socket and Local's own PHP build
from Local's `sites.json` — and raises the memory limit, because the site runs enough plugins to
exhaust PHP's 128 MB default before WordPress has finished loading:

```bash
./bin/wp plugin list
```

Note that the host CLI is PHP 8.5.x while the site runs 8.2.29. Composer pins `config.platform.php`
to 8.2.29 so dependency resolution targets the runtime the code actually executes on.

The test site ships with WooCommerce's **coming soon** mode on, which serves a placeholder to
logged-out visitors and makes every frontend check look broken. Turn it off before testing the
storefront by hand:

```bash
./bin/wp option update woocommerce_coming_soon no
```

## Commands

```bash
composer lint           # phpcs against the WooCommerce standard
composer lint:fix       # phpcbf — fix what can be fixed automatically
composer test           # PHPUnit against the WordPress test library
composer test:coverage  # …with a coverage report in coverage-html/
composer i18n           # re-extract the POT and recompile the translations
composer i18n:check     # is the POT still in step with the source?
composer check          # everything CI checks: validate, version, i18n, lint, test
composer build          # build dist/woo-organization-accounts-pro-<version>.zip
./bin/wp <args>         # wp-cli against the Local site
```

`bin/install-wp-tests.sh` provisions the `woo_org_tests` database once, before the first
`composer test`.

## Coding standards

`phpcs.xml.dist` (ruleset `WooCommerce-Core`) is the authority — when this document and the sniffs
disagree, the sniffs win. A PostToolUse hook runs `phpcbf` then `phpcs` on every PHP file written or
edited in this repo and blocks on remaining violations, so standards are enforced rather than
remembered. If the hook reports that phpcbf reformatted a file, re-read it before editing again.

- Tabs for indentation, not spaces.
- Yoda conditions: `if ( 'value' === $variable )`.
- Spaces inside parentheses: `function foo( $bar )`, `if ( $baz )`, `array( 'key' => 'value' )`.
- `snake_case` for functions and variables, `PascalCase` for classes, `UPPER_SNAKE_CASE` for constants.
- Every file, class, method and property carries a docblock. Inline comments are full sentences
  ending in a period.
- Every user-facing string is translated with the `woo-organization-accounts-pro` text domain, and
  uses `printf`-style placeholders rather than concatenation.
- Class files follow PSR-4 (`includes/Data/Organization.php`), not WordPress's `class-*.php`
  convention — the ruleset already excludes `includes/` from `WordPress.Files.FileName`.
- **The four concrete email classes are the one exception, and they are global.**
  `WooOrgAccounts_Invitation_Email` and its three siblings carry no namespace, are listed in
  `composer.json` under `autoload.classmap` rather than `autoload.psr-4`, and so need a
  `composer dump-autoload` when one is added. See *Emails are named for a URL* below.

Three sniffs are relaxed, each scoped to the files that genuinely need it and each with the reason
written into `phpcs.xml.dist`: the hook prefix and hook-comment rules inside `templates/emails/`,
which are required to fire WooCommerce's own header and footer hooks; and the capability sniff,
which cannot discover capabilities this plugin grants at runtime.

## Security — not negotiable

- **Sanitise on input**: `sanitize_text_field()`, `absint()`, `sanitize_email()`, `wp_unslash()` on
  every `$_POST` / `$_GET` value. Never read `$_REQUEST`. Passwords are the one thing never
  sanitised — they are hashed, never echoed, and altering what somebody typed would be a bug.
- **Escape on output**: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()` — at the point of
  output, not at assignment.
- **Authorise every state change**: `check_admin_referer()` / `wp_verify_nonce()` *and*
  `current_user_can()`. A nonce alone is not authorisation. On the frontend, use
  `Guard::check_request()`, which does both and returns the organization being acted on.
- **Never invent an input.** Before adding a field, work out where the value ends up. If it
  ends up on an order, it is a WooCommerce field and has to be collected with WooCommerce's own
  definition of that field — see *Address fields* below. A field with no destination is worse than
  a missing one: somebody fills it in and nothing uses it.
- **Every field this plugin defines is prefixed `woap_`.** WordPress reads its 82 public query
  variables out of `$_POST` as readily as out of the URL, so a form posting back to its own page
  with a field called `name` sets the post-slug query var, resolves the main query to nothing and
  returns a 404 — after the write has landed. The only exceptions are the WooCommerce address
  blocks, which must keep `billing_` and `shipping_`.

  Two tests guard it, and the weaker one is not enough on its own.
  `AccountHandlersTest::testNoFieldNameCollidesWithAWordPressQueryVar` reports a collision that
  already exists; `testEveryPostedFieldIsPrefixed` is the rule, because staying clear of that list
  by inspection is luck — it is WordPress's list to change and a plugin can add to it. The
  registration templates were the ones outside the rule until 0.6.0: they posted `password`,
  `tax_id`, `first_name` and five others under their bare names, and nothing was wrong except that
  the only thing keeping them safe was that none of the eight happened to be a query variable.

  **The invitation token is `woap_invite` in the form as well as in the link** —
  `Invitations::QUERY_VAR`, read from `$_GET` or `$_POST` by `token_from_request()`. It is posted
  back so a rejected submission returns to the invitation it was about rather than to the
  organization registration form, and one value with two names is how those two drift apart.
- **Frontend forms are handled on `template_redirect`, never through `admin-post.php`.**
  WooCommerce decides what to load from `is_admin()`, and `admin-post.php` is an admin request:
  `wc_load_cart()` never runs there, so `wc_add_notice()` is undefined and `WC()->session` does not
  exist. A handler there cannot tell the customer what happened — it fatals trying. Including the
  notice functions by hand does not fix it either; `wc_add_notice()` then finds no session and drops
  the message silently. `AccountHandlersTest` asserts none of these handlers drift back onto
  `admin_post_*`.
- **Scope every read and write to an organization.** The two questions — does this user hold the
  capability, and does this user belong to *this* organization — are easy to answer separately by
  mistake, and answering only the first is the whole of cross-tenant access. `Guard` answers both.
- **Prepare every query**: `$wpdb->prepare()` for anything with a variable in it. All SQL lives in
  `includes/Data/` and `includes/Install.php`; nothing else in the plugin contains a query.
- **Never trust the checkout.** Billing is written from the organization row and the submitted
  values are discarded; shipping is resolved from a location ID that is looked up scoped to the
  member's own organization. Readonly attributes are presentation.
- **Never commit a real credential**, including as a test fixture.

## Architecture

```
Organization ── Members ── (WordPress users)
     │             └─ location access
     ├── Locations
     ├── Invitations
     ├── Billing address (centralised, one per organization)
     └── Orders (WooCommerce, tagged with the organization)
```

### Designed for Woodmart

Because the theme is a hard requirement, the frontend is built as part of Woodmart rather than
layered on top of it. `WoodmartTest` covers the parts that can be asserted without the theme
installed.

- **No colour literals.** Every colour in `assets/css/account.css` and `assets/css/checkout.css`
  reads a Woodmart custom property — `--wd-text-color`, `--wd-primary-color`, `--brdcolor-gray-*`,
  `--notices-success-bg` and so on. The theme redefines those per colour scheme, so a shop on the
  dark scheme carries these screens with it instead of leaving a patch of wp-admin grey. The literal
  inside a `var()` fallback is Woodmart's own default and applies only if the theme drops the token.
  The single exception is `--woap-danger-color`: Woodmart's Theme Settings define success and
  warning notice colours but no error colour, so the plugin declares that one and nothing else.
- **Woodmart loads its stylesheet in parts**, each only where the theme itself uses it, so a screen
  that borrows the theme's tables, notices or login form has to ask for them by name. Always through
  `Templates::enqueue_theme_parts()`, which is guarded with `function_exists()` because
  `woap_theme_supported` can be filtered true off Woodmart — `WoodmartTest` asserts nothing else
  calls `woodmart_enqueue_inline_style()` directly.
- **Everything ties with `base.css`, so order and specificity both matter.** Woodmart styles every
  button on the site through `:is(.btn, .button, button, [type="submit"], [type="button"])`. A
  `:is()` takes the specificity of its most specific argument — here a class — so any plain
  single-class rule of ours ties with it, and a tie is settled by source order. Two bugs came out of
  that, and both are worth remembering because each looked like something else:
  - **Ask for a theme part while rendering, never on `wp_enqueue_scripts`.** A part requested that
    early loads *before* `base.css` and loses every tie. `woo-mod-login-form` sets
    `.show-password-input { position: absolute }`; enqueued too early it became `relative`, and the
    show/hide-password control dropped out of the field and sat underneath it while the input kept
    the 42px of padding reserved for it. Woodmart's own templates call it during render for exactly
    this reason, which is why the theme's own login page was unaffected.
  - **Qualify our own button selectors with the element name.** `.woap-link-button` tied and lost,
    so every secondary action inside the locations and invitations tables rendered as a full grey
    42px-tall button. `button.woap-link-button` wins outright and does not depend on load order.
    Its underline additionally needs `!important`, because the theme's rule declares
    `text-decoration: none !important`.
- **The registration page is forced to Woodmart's full-width layout.** It is an ordinary page, so
  the theme gives it the site default — on a stock install a right sidebar of blog widgets beside a
  twenty-field form. `Registration::page_layout()` supplies `full-width` on the
  `woodmart_get_page_layout` filter, and defers to a layout set explicitly on the page so there is
  still one answer. Woodmart makes the same correction for its own account pages, which is why
  those looked right and this one did not.
- **Tables are `shop_table shop_table_responsive`, and every `<td>` carries a `data-title`.** On a
  narrow screen Woodmart hides the header row and prints `attr(data-title)` in front of each value
  instead. A table with the class but not the attributes is a column of unlabelled values on a
  phone — worse than the unstyled table it replaced. The header labels and the `data-title`s are
  generated from one array per template so they cannot drift apart.
- **The account navigation items need their own icons, and the code point has to be one the font
  assigns.** Woodmart tags each item with `wd-my-acc-<endpoint>` and reads `--wd-my-acc-nav-icon`
  from it, defining the variable for its own endpoints only. `MyAccount::nav_icons()` supplies a
  woodmart-font code point per endpoint, emitted inline on every account screen — not only the
  plugin's own, since the menu is drawn everywhere — and from the endpoint constants, so a slug
  cannot drift from a hand-written stylesheet. woodmart-font is **not a complete range**: an
  unassigned code point renders as nothing, leaving the item with the space for an icon and no icon,
  which is what `\f132` did to Invitations. Check a new one against the theme before using it —
  `grep -c '\f157' wp-content/themes/woodmart/css/style.min.css` — because CI has no theme to check
  it against, and `AccountTest::testNavigationIconsAreGlyphsTheThemeAssigns` can only hold the list
  to the ones already verified that way.
- **Buttons take Woodmart's colour variants.** `.button` is already the theme's button; adding
  `btn-color-primary` or `btn-style-bordered` picks the variant. `.wd-login-title` is deliberately
  *not* used: it carries no styling at all and exists only for the theme's login-tabs script.
- **The registration screens use `wd-registration-page`**, the theme's centred 1000px container.
  The invitation form adds `wd-no-registration`, which narrows it to 450px; the organization
  registration form must not, because it would squeeze a twenty-field form including a full billing
  address into a single strip. Neither uses the theme's `wd-grid-f-col` / `col-register` grid, which
  exists to seat a login column beside a register column — and there is no login column, because
  WooCommerce's registration is switched off while the plugin is active.

**The plugin's settings are shown in Woodmart Theme Settings, not stored there.** `Admin\
ThemeSettings` adds a read-only panel under the theme's *My account* section that reports the
current configuration and links to the plugin's own screen. Woodmart writes its entire settings
screen as one `xts-woodmart-options` array, and both *Reset to default* and the demo-content
importer overwrite that array wholesale. `organization_mode` renames every organization noun on the
site and `require_approval` decides whether a new account may buy anything; importing a demo to try
a homepage layout must not quietly rename half the shop or reopen it. Editable copies would also
leave two answers to one question with no defined winner — the same objection as a capability on the
role, or an organization type column.

### Site-level organization mode

The site runs in exactly one mode — **Business / Company** or **Educational Institute** — chosen
once in the settings. It changes what things are *called* and nothing about what is stored.

**There is deliberately no organization type column.** A company and an institute are the same row
with different nouns on screen, so storing the type per organization would invite mixed sites the
rest of the plugin has no answer for. `Labels` reads the one global setting and returns the right
noun; `LabelsTest` asserts that no such column exists.

Each noun is a separate translatable string per mode rather than one string with the noun
substituted in, because German has to translate "Company" and "Institute" independently. Sentences
that contain a noun use a `printf` placeholder and take the noun from `Labels`, which keeps the
string count finite.

### Tables

Five tables, created by `Install` with `dbDelta()` and re-checked against `WOAP_DB_VERSION` on
every load — WordPress updates a plugin by unpacking files over the old ones without deactivating
it, so activation alone would never run a schema change on a live site.

| Table | Holds |
|---|---|
| `woap_organizations` | the account, its status and its centralised billing address |
| `woap_members` | the link between a WordPress user and an organization |
| `woap_locations` | the places an organization has goods delivered to |
| `woap_member_locations` | which locations a member is restricted to; empty means all |
| `woap_invitations` | outstanding invitations, holding only a SHA-256 of the token |

`woap_members.user_id` is **UNIQUE**. That is what makes "one user, one organization" a rule the
database keeps rather than one every insert has to remember, and it is why "which organization is
this order for?" has exactly one answer.

Relational tables rather than post types: these are relationships queried by status and by owner on
every request that touches the checkout, and post meta would turn each of those into a join against
a table the whole site shares — with no way to make the uniqueness above a constraint.

**A column that nothing reads is deleted, not left alone.** `dbDelta()` adds and widens columns and
never removes one, so `Install::drop_retired_columns()` is what actually retires a column, and
`InstallTest::testEveryEntityDeclaresExactlyItsTablesColumns` is what keeps the schema and the
entities from drifting apart in either direction. The drift matters in both: a column in
`defaults()` with none in the table makes `Repository::save()` fail on a placeholder for a column
that is not there, and a column in the table with none in `defaults()` is the quieter fault —
`Entity::set()` ignores anything undeclared, so a fixture or a screen goes on writing to a retired
column and every assertion about it silently reads the default instead.

**An organization has no `email` or `phone` of its own**, and had both until 0.6.0. They sat beside
`billing_email` and `billing_phone`, which is the pair `Checkout\BillingLock` copies onto every
order and therefore the pair every WooCommerce order email is addressed to. Nothing read the other
two but four display templates and the admin search — so the registration form asked for three
email addresses and three phone numbers, the screens could show one address while the shop wrote to
another, and registration insisted on an address the account screen would then let you blank.
`Install::migrate_organization_contacts()` copies each onto its billing counterpart before the
columns go, and only where that counterpart is empty, so a real billing address is never
overwritten by the weaker copy.

Retired with them: `woap_locations.date_created` and `woap_invitations.date_accepted`, both written
since the first release and read by nothing. `woap_invitations.invited_by` was in that state too and
went the other way — the invitations list now has a *Sent by* column, because an organization can
have several people holding `woap_invite_members` and an invitation somebody did not send is worth
being able to place before deciding whether to withdraw it.

### Address fields

**A member has no address of their own, and WooCommerce's own address screen is refused.** Billing
is the organization's, written onto every order by `Checkout\BillingLock`; shipping is a location,
resolved by `Checkout\ShippingSelector`. Both discard whatever is stored against the WordPress user,
so `edit-address` accepted a twelve-field address, reported "Address changed successfully" and
changed nothing that would ever reach an order.

**Unsetting the menu item was never enough**, and that is why this survived a release.
`MyAccount::add_menu_items()` removes `edit-address` from one array, which decides what the account
sidebar prints and nothing else: the endpoint is a rewrite rule WooCommerce registers at `init`, so
the URL still routed, still rendered and still saved. WooCommerce's own `dashboard.php` prints a
live link to it in its opening sentence too, so the screen was reachable from the first page a
member lands on without going near the menu. The plugin's own five endpoints do not have this
problem because every `render_*()` calls `guarded_organization()` — the menu is decoration and the
check happens at render.

`MyAccount::refuse_address_endpoint()` is the refusal, on `template_redirect` at **priority 5**,
because `WC_Form_Handler::save_address()` is on the same action at the default priority and
refusing after it would refuse the screen while keeping what it submitted. It is a request-time
refusal rather than a blanked endpoint slug — the way registration is switched off — because an
endpoint slug is baked into the site's shared, cached rewrite rules, and making one conditional on
who is asking would leave those rules dependent on whoever happened to flush them. **Non-members are
left alone**: an administrator, an author or a plain customer still has an address of their own.

`Frontend\AddressFields` is the only place the plugin builds an address form, and it builds none
of it itself: the fields, their labels, their order, which are required and how each is validated
all come from `WC()->countries->get_address_fields()` and mirror
`WC_Checkout::validate_posted_data()`. Three screens use it — registration, the account billing
address, and the location form — and the admin organization screen uses the same helper, so all
four ask for exactly what the checkout will ask for.

This is not tidiness. A hand-written address form is wrong in a different way in every country: it
asks a German customer for a state they do not have, gives a Canadian free text where their courier
expects a province from a list, calls a ZIP a postcode in Ohio, and accepts "Californa". The first
version of this plugin did all of those, and an organization could register with an address its own
checkout then rejected.

- **The prefixes and wrapper classes are load-bearing.** `country-select.js` looks for
  `#billing_state` / `#shipping_state` inside `.woocommerce-billing-fields` /
  `.woocommerce-shipping-fields`, by those exact names. Rename either and the state field silently
  stops following the country on a screen that still looks correct.
- **The server renders the right control on its own**, and WooCommerce's scripts are layered on top
  for live switching. Both scripts return early when their parameters are missing, so a future
  WooCommerce rename degrades to a correct static form rather than a broken one. The admin needs
  the scripts registered by hand, because WooCommerce registers them for the frontend only.
- **A location is a WooCommerce shipping address, column for column** — `first_name`, `last_name`,
  `company`, `address_1`, `address_2`, `city`, `state`, `postcode`, `country`, `phone` — plus a
  `name` that is only the label in the checkout selector. Nothing is derived at checkout. Schema
  1.0.0 stored a single `contact_name` and split it on whitespace when an order was placed, which
  gave every one-word contact an empty surname; `Install` migrates those once and drops the old
  columns.
- **A blank company falls back to the organization's name**, at save time and again when the order
  is built, because a parcel with no company on the label is one nobody at a loading bay
  recognises.
- **Two shipping fields are relaxed, and only these two**, in `AddressFields::delivery_fields()`.
  WooCommerce's shipping fields describe an address somebody is typing at a checkout; these
  describe one the shop has kept on file, and a rule that is fair to apply to a keystroke is not
  automatically fair to apply retroactively to records that already exist. **`last_name` is never
  required**, because a delivery address belongs to a place at least as often as to a person —
  "Warehouse North" has no surname, and demanding one produces an invented one on a parcel label.
  The first name stays required, so the parcel is always addressed to something, and
  `location-form.php` is where the person filling it in is told which of the two they are doing.
  **`phone` is never required either**, whatever `woocommerce_checkout_phone_field` says: that
  setting is a rule about the person buying, and `missing()` decides whether a location can be
  shipped to at all — so inheriting it would mean a shop switching the setting on made every
  location it had already saved undeliverable, as a refusal at somebody's checkout about a record
  only an admin can edit. Both are still validated when they are filled in, and a shop that hides
  the phone entirely is left alone: WooCommerce removes the field before this sees it, and nothing
  puts one back. `docs/rest-api.md` says the same to the till, which has to be told or it will
  refuse an address the shop's own screens accept.
- **A rejected submission is handed back, not redirected away.** Losing a twelve-field address to
  one mistyped postcode is not an acceptable way to report an error. Every field that was rejected
  is marked `woocommerce-invalid` with the reason under it, because a notice at the top of a
  fourteen-field form says *something* is wrong without saying where. The admin screen has to
  redirect — `admin-post.php` prints nothing — so it parks the whole submission in a one-minute
  transient and refills the form from it.

### Editing one of many

A screen that writes one row of a list is its own screen, reached by a query argument
(`?woap_location=<id>` or `new`, `?woap_member=<id>`, `?woap_invite=new`), never a form underneath
the list — and never a form folded away above it either, which is the same mistake wearing a
disclosure triangle. A primary button called *Invite somebody* has to lead to the form; leading to
the list it was already on, with the form shut inside it, is a button that does not do what it
says. Three
things go wrong with the form-under-the-list shape, and the locations screen had all of them: you
scroll past everything else to reach it, nothing says which row is open, and — the real bug — the
row being edited lives only in a query argument the form does not post back, so a rejected
submission returns as a blank *add* form and saving again creates a duplicate instead of correcting
the original. The form posts to its own URL, argument included.

**Members follow the same shape, and the cost of not doing so was worse there.** That screen was one
`<details>` per member, each holding a role, a status, seven permission checkboxes and a checkbox per
location, so an account with fifty employees shipped fifty forms to answer *who works here* — and
the answer was inside them rather than on the page. The list is now a row per person reporting role,
delivery access, whether permissions follow the role, and status; `?woap_member=<id>` is where any
of that changes.

### One set of components across the five screens

Every account screen is built from the same handful of pieces, so five screens read as one product:
a `woap-account__header` (a sentence saying what the screen is for, and the primary action beside
it), `woap-panel` for a bordered group, `woap-identity` + `woap-meta` for "what this record is",
`woap-empty` for nothing-here-yet, `woap-status` pills, and `woap-table__title` / `woap-table__meta`
inside a cell. **An empty state names what is missing, says what cannot happen until it exists, and
carries the button that fixes it** — the locations one is the example, because with no locations
nobody on the account can check out at all.

`woap-choice` is the pattern for a setting whose stored form is ambiguous on its own: radios naming
the answer, and a detail block per answer. Two settings need it, and both were previously stored as
a bare list whose *empty* case meant the opposite of what the checkboxes suggested — see
*Capabilities* below and `AccountHandlers::restricting_locations()`. The radio is what the server
reads; `account.js` only disables the block that is not the answer, so the screens work unchanged
with JavaScript off.

### Capabilities

Two WordPress roles, `woap_org_admin` and `woap_member`, carry `read` and **nothing else**. Every
capability the plugin defines is granted at runtime by `Capabilities`, on the `user_has_cap` filter,
from the membership row: the role default, with the member's own JSON overrides layered on top.

Putting the capabilities on the role as well would create a second answer to "may this person invite
members?" that could disagree with the first — and a capability that outlives the membership it came
from is exactly how somebody keeps buying on an organization's account after being removed from it.
Anyone with `manage_woocommerce` holds all of them, for any organization.

Because it is the ordinary `user_has_cap` filter, every screen, nonce check and REST
`permission_callback` can just ask `current_user_can()`.

**Only the differences from the role are stored, so the member form has to ask which of the two it
is being told.** Overrides are a diff, and a diff is meaningless without knowing what it is against:
the form used to derive one from checkboxes drawn for the role the member held *before* the
submission, so promoting an employee to admin stored "everything off" as six overrides against the
admin defaults and produced an organization admin who could manage nothing. The form now asks
outright — *whatever the role allows*, or *choose them one by one* — and the first answer stores no
overrides at all. `AccountHandlersTest::testPromotingToAdminGrantsTheAdminDefaults` is that bug.

**Holding one of our capabilities and being able to reach what it describes are two facts, and only
the first is ours to assert.** `woap_view_organization_orders` decides who sees the organization
orders list; the page every row *links to* is WooCommerce's `view-order` endpoint, and
`wc_customer_has_capability()` grants `view_order` only to the order's own customer. So an
organization admin got a correct list of their organization's orders in which every row placed by
an employee answered "Invalid order." Neither side was wrong — the list was right and the
capability was right — and nothing asserted the two together.

`Capabilities::resolve_order_capabilities()` closes it, for **all five** capabilities WooCommerce
keys on the order's customer — `view_order`, `order_again`, `cancel_order`, `pay_for_order` and
`download_file`. An order belongs to the organization, and which employee happened to place it is a
detail of how it got there, so somebody who may see the organization's orders has the same control
over them as over their own.

`pay_for_order` **additionally requires `woap_place_orders`**. Paying is spending, and that
capability is the plugin's whole expression of who may spend; an admin holds it by default, so the
extra condition only bites where somebody has been explicitly refused it. Without it, revoking a
member's right to buy would leave them able to settle the organization's unpaid orders instead —
and `Checkout\Gate` would not catch that, because `WC_Form_Handler::pay_action()` validates the
order key rather than running the cart and checkout hooks the gate is attached to.

Three properties of the shape are deliberate. It **only ever grants**, never denies, so WooCommerce's
own rule still gives a member their own order and a plain customer elsewhere on the shop is
untouched — a filter answering in both directions would make this plugin the arbiter of who may read
every order on the site. It is **scoped to orders carrying `_woap_organization_id`**, because an
order with no organization is not this plugin's business whoever is asking. And it is **scoped to
the asker's own organization**, which is the cross-tenant question and separate from the capability
one.

`download_file` is handed a `WC_Customer_Download` rather than an order, so the order is reached
through it — and checking that capability with an order ID makes WooCommerce itself fatal on
`$download->get_user_id()`, long before this plugin is consulted. It is granted because it is
reachable: `order-details.php` renders a download button per item, so an admin who can open the
order can see them. **The downloads *list* at `/my-account/downloads/` is still per-user**, because
`wc_get_customer_available_downloads()` queries by user ID; an admin reaches an employee's file from
the order, not from that screen.

**Capabilities were only half of it: WooCommerce also decides what an order *page* shows.**
`Frontend\OrderDetails` supplies the billing and delivery addresses at the foot of an order the
viewer did not place. Both `order/order-details.php` and its fulfillments variant decide with the
same bare line — `$show_customer_details = $order->get_user_id() === get_current_user_id();` — so an
admin who could now open an employee's order got the items and the totals and no addresses, which
on an account whose whole point is that goods go to organization locations were the two facts most
worth checking.

**There is no filter for it**, so the choice is to override the template or print the block
ourselves. Overriding would freeze a WooCommerce template that still changes between releases —
the fulfillments variant is recent — against a plugin that carries no compatibility shims. Instead
this hooks `woocommerce_after_order_details`, which both templates fire on the line immediately
before the block they are about to skip, and renders WooCommerce's own
`order/order-details-customer.php` into the position it would have occupied. It stands down when
WooCommerce is going to render the block itself, or every address would appear twice.

Worth knowing when writing fixtures for this: WooCommerce shows the address *columns* only when
`needs_shipping_address()` is true, and that reads the order's **shipping methods**, not its
products. An order without a shipping line falls back to printing the billing email alone.

**The test that catches this class is an invariant over the screen, not a case.**
`CapabilitiesTest::testEveryListedOrderIsOpenableByTheReader` asserts that every order the list
returns can be opened by the member it was rendered for. A per-case test would have been written
against the same assumption that produced the bug; the invariant fails for any future row this
plugin lists and WooCommerce would refuse. Whenever a screen here links into a WooCommerce
endpoint, that is the seam to test — our side alone always passes.

### Checkout

`Context::can_purchase()` is the one expression of the purchase rule: logged in, an active member,
of an active organization, holding `woap_place_orders`. What `Checkout\Gate` adds is the list of
places it has to be applied — the cart, the classic checkout's validation pass, and the Store API,
which is a public REST route reachable whatever the site's pages happen to render.

- `BillingLock` overwrites every billing field from the organization row and **discards what was
  submitted**, on `woocommerce_checkout_posted_data` (so validation runs against the address that
  will be used) and again on order creation. The copy WooCommerce puts on the order is the
  historical snapshot: editing the organization's address next month leaves old orders unchanged.
- `ShippingSelector` resolves a location **by ID, scoped to the member's own organization and their
  access list**, and writes that address onto the order. A one-off address is allowed only when the
  organization's own switch is on.
- `OrderMeta` records `_woap_organization_id`, `_woap_location_id`, `_woap_member_user_id` and the
  names of the first two. Both the IDs and the names are needed: the IDs link back to the live
  records, the names are what an order says it was for after a rename or a deletion, and an order
  list must not load five hundred organizations to print one column.

Both checkout surfaces are supported, because WooCommerce 11 gives a new store the block checkout by
default. `Checkout\Blocks\CheckoutIntegration` exposes the organization's billing address and
locations on the cart, and accepts the chosen location back, through the Store API extension
namespace. **The block script is plain JavaScript against the globals WooCommerce already enqueues —
no JSX, no bundler** — so the plugin still builds with nothing but Composer and CI's
`node --check assets/js` stays meaningful. Nothing in that script enforces anything; the server
does.

### The REST surface a till talks to

**`docs/rest-api.md` is the client-facing reference** — routes, parameters, payloads, error codes
and both consumers' end-to-end flows. It is written for the developer of the consuming app, this
section for the developer of the plugin; a change to either half of the surface updates both.

Two halves, split by whether WooCommerce already has the noun. This plugin registers routes only
for what WooCommerce cannot say; anything it already models is extended on its own route instead,
so there is one code path per thing. An order is the case that matters — `/wc/v3/orders` is a third
way an order comes into existence, beside the two checkouts, and `Rest\Orders` holds it to the same
rules.

`Rest\OrganizationsController` serves `GET /wp-json/wc-woap/v1/organizations` — every organization
with its members and its locations embedded, for a point-of-sale device that syncs on an interval
and then sells offline.

**The namespace serves two consumers, and which one a route is for decides its shape.** The till
above reads; a back-office app writes — the screen where somebody reviews a registration and
approves it, corrects a billing address, adds a branch, puts an employee on an account. Until 0.8.0
there was no way to do any of that but wp-admin, which is fine for a shop and useless for an app.
The writes live beside the reads rather than in a namespace of their own because they are the same
nouns, and both directions go through the same payload builders so one organization cannot describe
itself two ways. `Rest\Writes` holds what they share: the capability check, the parent lookup, the
address merge and the refusal.

- **`manage_woocommerce` on every route, reads and writes alike.** The plugin's own capabilities
  answer what a member may do to *their* organization; approving one is not that question, and an
  organization admin approving their own registration is why it cannot be.
- **The status is a route of its own** (`POST /organizations/<id>/status`), not a field on the edit,
  and an edit carrying one is refused rather than ignored. Moving between statuses is what fires
  `woo_org_accounts_organization_status_changed` — the approval and rejection emails, and
  `LoginGate` — so it must go through `OrganizationRepository::set_status()` and must not be
  reachable by a client round-tripping an object it fetched. Asking for the status already held is a
  success with `changed: false` and no mail, because two people working one review queue must not
  send two approval emails.
- **A write reuses the screens' own validators**, so `AddressFields::validate()` and
  `Organization::validate_details()` decide, and the errors they key by form field name are
  rewritten to the payload path the client sent and stripped of their `<strong>` markup — a `params`
  map in WordPress's own `rest_invalid_param` shape, so a form can mark the field rather than show a
  banner over fourteen of them.
- **An edit is partial, and declares no argument defaults.** WordPress fills a declared default into
  the request, so `'default' => ''` on `tax_id` would blank the tax ID of every organization renamed
  through the route — and `''` on `first_name` made every invitation arrive carrying a field an
  invitation is not allowed to carry. Both were caught by tests rather than by reading.
- **A submitted address is merged onto the stored one and then validated whole**, because which
  fields an address needs depends on its country: validating only what was sent would let an edit
  changing `country` alone leave a US address with no state. The cost is that editing an address
  stored incomplete refuses until the missing field is supplied — which is `AddressFields::missing()`
  behaviour, deliberate, and why an edit that says nothing about the address skips the check.
- **No route deletes an organization.** That cascades members, locations and invitations and resets
  everybody's WordPress role; `suspended` and `rejected` are the reversible answers, and wp-admin
  keeps the irreversible one.
- **Permissions are sent only where they are edited** — the member routes, never the snapshot, which
  `RestApiTest::testTheSnapshotOmitsWhatTheDeviceMustNotDecideFrom` already pinned. What is sent is
  the *resolved* map and a `capabilities_follow_role` flag, never the stored diff. `MembersController`
  does the diff arithmetic on the way in, against the role *being saved*, which is
  `testPromotingToAdminGrantsTheAdminDefaults` asked of a REST client.
- **Adding somebody is two acts, and the request says which.** `invite` issues an invitation and
  cannot carry membership fields — there is no row for them to land on, so they are refused rather
  than dropped; `create` makes the account outright, the importer's shape, random password and no
  mail. Both refuse an address that already belongs to an organization, and neither ever demotes
  somebody holding `manage_woocommerce`.

- **The `wc-` prefix on the namespace is what makes a consumer key work.**
  `WC_REST_Authentication` reads a key and secret only after `is_request_to_rest_api()` finds `wc/`
  or `wc-` in the request URI, and core's own comment beside that line offers the second prefix to
  third-party plugins. A namespace of `woap/v1` registers, routes and answers every till with a 401,
  because the credentials they hold are never read. Nothing else in the suite would catch it — the
  tests sign a user in directly, which is not how a till arrives — so
  `RestApiTest::testTheNamespaceKeepsWooCommercesAuthenticationPrefix` asserts the prefix.
- **It is a full snapshot, and the schema is why.** Only `woap_organizations` carries a
  `date_modified`; members and locations record `date_created` and nothing else, and every delete is
  a hard delete with no tombstone. A `modified_after` parameter could be offered but never honoured:
  a device asking for yesterday's changes would not be told that an employee left or that a location
  was deleted, and would go on offering an address the organization has abandoned. A snapshot
  answers deletions by omission, which is the one thing this schema can say truthfully. An ETag and
  `If-None-Match` make an unchanged page a 304, so polling costs a hash rather than a payload.
- **Members and locations are embedded, not paged separately**, so a page cannot be torn by a write
  landing between two requests — and the page is ordered by **ID**, because ordering by name lets a
  rename between page one and page two drop an organization out of the sync entirely.
- **Answers are sent, not inputs.** `can_place_orders` is `Context::can_purchase()` resolved
  per member, and location access is `"all"` or a list of IDs. A device deriving either for itself
  would be a second expression of rules this plugin states once — and the two stored forms are
  actively misleading read raw: an empty `woap_member_locations` means *every* location, and the
  `capabilities` column is a diff against role defaults, so an empty one means "whatever the role
  allows" rather than "nothing". Neither is sent.
- **Permission is `manage_woocommerce`, deliberately not one of this plugin's own capabilities.**
  Those are granted from a membership and answer what somebody may do to *their* organization; the
  answer to that is never "read every organization on the site".
- Every read is batched — `MemberRepository::for_organizations()`,
  `LocationRepository::for_organizations()`, `MemberRepository::location_ids_for_members()` and one
  `cache_users()` — because per-organization reads turn a hundred-row page into several hundred
  queries, which is the first thing an interval sync would hit. Each returns an entry for every
  organization asked about, so "has no members" and "not asked about" cannot look alike.
- **The tax ID is not in the payload.** All of this lands on a device that can be left on a counter
  or lost, and it is the one regulated identifier in the table. A shop whose till prints invoices
  can add it.
- **Addresses are also served formatted for their country** (`billing_formatted`, `formatted`
  per location, newline-separated), through `WC()->countries->get_formatted_address()` — postcode
  before the city in Germany, after it in the US — so the device never invents an envelope layout.

**`Rest\AddressFormController` serves `GET /wc-woap/v1/address-form`** — WooCommerce's shipping
field definitions per ship-to country, serialised from the same `AddressFields::fields()` every
web form here renders from, shop checkout customisations included (the
`woo_org_accounts_address_fields` filter applies). It exists because a one-off delivery address is
the one address a till composes, and a hand-written address form is wrong in a different way in
every country — the founding lesson of `AddressFields`. Only the shipping form: billing comes from
the organization row and locations arrive pre-validated in the snapshot. The state field carries
its `options` where the country has a list; absence means free text, which is what the checkout
renders too. ETag'd like the snapshot; `Rest\Etag` is the shared revalidation helper.

**`Rest\Orders` is the write side, on WooCommerce's own orders route.** Left alone, `/wc/v3/orders`
was the documented way around everything this plugin enforces: an order created there carried
whatever billing the device posted, no organization, no location and no `woap_place_orders` check —
so it billed wrong and then appeared in no organization's order list at all. Everything lands on
`woocommerce_rest_pre_insert_shop_order_object`, which fires after the request is written onto the
order and before it is saved, so a refusal is a clean error and no order exists. Nothing uses a
`register_rest_field` **update** callback: `WC_REST_CRUD_Controller` calls those *after* the order
is saved and discards their return value, so a refusal from one arrives too late and is then thrown
away. The `woap_*` fields on the order response are read-only `get_callback`s over `OrderMeta`.

- **The rules are applied to the order's customer, never to the API user.** A till's key maps to a
  user holding `manage_woocommerce`, and `Capabilities` grants that user everything for every
  organization — a gate asked about the key is always open, and every happy-path test would still
  pass. `Context::can_purchase()` and `purchase_blocked_reason()` take the customer's user ID;
  `ShippingSelector` grew `destination_error_for()`, `resolve_location_for()` and
  `order_shipping_address()` — the explicit-member forms of what the checkout does for the
  signed-in user — so the till is refused exactly where the member would be, access list included.
- **A customer with no membership is left alone** — same rule as the order capabilities: no
  organization, no opinion. Staff placing a manual test order must not be refused by a gate about
  organizations they do not belong to.
- Billing is overwritten from the organization row and the posted address discarded; the delivery
  location arrives as `woap_location_id` — the same field name the classic checkout posts — and an
  order that `needs_shipping_address()` is held to the location-or-permitted-one-off rule. One that
  needs no shipping needs no location: a walk-out sale at the counter has no parcel.
- **A one-off address is validated with `AddressFields::validate()`** — the checkout's per-country
  rules, because a till taking a customer's delivery address is the checkout case, not the
  staff-typing-a-partial-record case `/wc/v3/orders` was built for. Same rules, same
  normalisation (postcode formatted, state name to code), refusal `woap_rest_shipping_address`
  with the field-naming messages stripped of their `<strong>` markup.
- **Updates re-run nothing.** An update over this route is shop staff editing a record they own —
  the same act as editing it in wp-admin, which this plugin also leaves alone — and re-running the
  gate against an old order would refuse edits to history whenever a member has since left. The one
  organization-shaped edit an update accepts is `woap_location_id`, resolved against the
  *organization on the order* (the access list governs what a member may choose; this is the shop
  redirecting a parcel it is fulfilling), cross-organization still refused.
- `?woap_organization=<id>` on the orders collection scopes the list, through
  `woocommerce_rest_orders_prepare_object_query` — the same indexed meta lookup the account's
  organization-orders screen runs.
- `RestOrdersTest` ends on the seam invariant: an organization admin can *open* a till-created
  order, which asserts the stamp and WooCommerce's `view_order` answer together.

### Analytics knows nothing about a role it has not been told about

WooCommerce → Customers reads `wc_customer_lookup`, not the users table, and a user only
reaches that table if one of their roles is in a list WooCommerce defaults to `array( 'customer' )`.
Every account here holds `woap_org_admin` or `woap_member` instead — a member is a customer of the
shop but never WooCommerce's `customer` role, because that role carries the account screens this
plugin replaces. On a shop whose customers are *all* organizations, the default empties the screen
completely, and with it the Customers report, the customer filter on every other report and the
customer CSV export, which all read that one table.

`Analytics` filters **both** role lists, because a user is written into that table by two paths and
each has its own: `woocommerce_analytics_import_customer_roles` is a `role__in` on the historical
backfill, and `woocommerce_analytics_customer_roles` is the per-user check on the ongoing sync.
Filtering one and not the other leaves either every existing account invisible or every future one.
It is registered outside the `is_admin()` branch, because the ongoing path runs from the
`wc_last_active` meta write on a signed-in member's *frontend* request — which is also why accounts
that predate the fix reappear by themselves the next time their owner signs in. Only an account
that never signs in again needs Analytics → Settings → *Import historical data*; there is no
Status → Tools entry and no wp-cli command for it.

### Emails are named for a URL

The plugin sends four messages, each a `WC_Email` subclass so it appears in WooCommerce →
Settings → Emails with the same switch, subject and heading fields, template overrides and
branding as every other message the shop sends. `Emails::add_classes()` registers them.

**The four concrete classes live in the global namespace — the only departure from PSR-4 in the
plugin — because a class name here is part of the plugin's URL surface.** WooCommerce identifies
an email to preview by `get_class()`: `EmailPreview::set_email_type()` matches the `type` query
argument against `array_map( 'get_class', WC()->mailer()->get_emails() )`, with no filter anywhere
between the class name and the wire, and the settings bundle interpolates the result straight into
the preview iframe's `src` as `` `${previewUrl}&type=${emailType}` `` — no `encodeURIComponent`.
A namespaced class therefore puts raw backslashes in a query string.

That is what broke. Many hosts run a WordPress-hardening snippet containing
`if ($args ~ ...) { return 403; }`, and a bare backslash trips it, so the preview returned nginx's
stock 403 for all four of these emails while every core `WC_Email_*` one worked. **No hook in this
plugin could have answered it**: nginx rejects the request before PHP is reached, which rules out
every idea that starts with filtering `$_GET['type']`. Changing what `get_class()` returns was the
only lever a plugin has.

Worth knowing about the rule that caused it, because it argues against fixing this on the server:
nginx's `$args` is the *raw* query string and is never percent-decoded, so `%5C` sails straight
through and PHP decodes it back to a backslash. The rule stops WooCommerce's own admin UI and
stops nobody else. It is still the wrong shape, and this plugin routes around it rather than
depending on every host getting it right.

- **`$this->id` is what a shop's settings are keyed by** — `woocommerce_{$id}_settings`, so
  `woap_invitation` and its three siblings. The class name is not in that key, which is why these
  could be renamed without a single shop losing its configured subjects and headings.
- **The two abstract bases stay namespaced.** `Emails\Email` and `Emails\StatusEmail` are never
  registered, so `get_class()` never returns either and neither reaches a URL.
- **The guard is an invariant, not a list.** `EmailsTest::testNoRegisteredEmailClassNameNeedsUrlEncoding`
  asserts `rawurlencode( get_class( $email ) ) === get_class( $email )` over everything actually
  registered. A test naming today's four would pass while a fifth email written back inside
  `WooOrgAccounts\Emails` reintroduced the bug.

### Invitations

Four properties have to hold together, and `InvitationTest` asserts each one:

- **The token is a secret.** Generated once, put in one email, stored only as a SHA-256 digest.
  Reading the database gives no way into anybody's organization.
- **It is bound to one organization and one address.** Holding a token is not enough; the account
  redeeming it must be the address it was sent to.
- **It works once.** Redeeming marks the row accepted, and an accepted row is not acceptable again.
  Re-sending replaces the token rather than adding a second one that also works.
- **It can be withdrawn or lapse.** Expiry is checked against the clock at redemption, never trusted
  from the link.

Every refusal — unknown token, expired, revoked, already used, wrong address — returns the *same*
message. Telling them apart would turn the link into a way of asking the site questions about
invitations the visitor was never sent.

### Registration

WooCommerce's own registration is switched off with `pre_option` filters, so it cannot be turned
back on from WooCommerce → Accounts while the plugin is active. An account belonging to no
organization cannot buy anything, so offering to create one would only produce a customer who cannot
check out. Accounts arrive through the `[woap_organization_registration]` shortcode — on a page
created at activation — or through an invitation. Both flows share that one shortcode: it shows the
join form instead of the registration form when the request carries a token. The importer below is
the third way, and the only one that is not somebody filling in a form.

**Switching WooCommerce's registration off leaves the theme's links to it pointing at nothing, so
`?action=register` is redirected rather than ignored.** `add_query_arg( 'action', 'register' )` is
Woodmart's own signal for *show me the register side*: the header account dropdown's *Create an
Account* link, the login page's *Create an Account* button and the theme's own register form all use
it. With WooCommerce registration off, every one of them lands on a My Account page showing nothing
but the login form — the visitor asked to sign up and the site answered by asking them to sign in.
`Registration::redirect_register_action()` sends them to the registration page instead, and sends
somebody already signed in to their account. GET only: a POST to that URL is a submission, and
swallowing one in a redirect would lose what it carried.

**`require_tax_id` is the one detail a site can make mandatory, and it is one predicate, not three.**
Insisting on a VAT number is right for a shop selling to companies in the EU and wrong for one
selling to schools or outside it, so it is a setting — off by default. Registration, the account
profile and the admin screen all ask `Organization::tax_id_required()`, because a field that
registration insists on and the profile screen lets you blank again is not a required field. That
was exactly the state the organization's email address was in before it was removed. **No format is
checked**: a VAT number, a company registration number and a US EIN look nothing alike, the rules
change with the country and the year, and a pattern that rejects a valid identifier is worse than
no pattern — the shop can see what was typed and this plugin cannot see whether it is real.

### Importing a shop that had no organizations

The third way an account comes into existence, and the only one that is not a person filling in a
form. A shop moving onto this plugin has customers who are not organizations yet: a flat export,
one row per person, in which the only evidence of who works together is the address they billed to.
`includes/Import/` turns that into organizations, members and locations; `Admin\Import` is the
screen, registered under the WooCommerce menu and then removed from it with
`remove_submenu_page()` — the page stays registered and capability-checked, and the organizations
list links to it, because a permanent menu item for something a shop does once is clutter on every
other day.

**The grouping key is company + street + postcode + town, and the person's name is deliberately not
in it.** The person is the member, not the organization. Keying on them splits the one case this
plugin exists for: on the 647-row export this was built against, including the name turned three
colleagues at one address into three organizations that could not see each other's orders. Company
plus a full street address is already specific enough that a false merge needs two unrelated parties
to share a company name *and* a street *and* a postcode.

The key **errs towards splitting**, because the two mistakes are not equally bad. An organization
that arrived as two is a merge away. Two unrelated customers merged into one are a privacy failure —
each can read the other's orders and ship to the other's addresses — and no later merge undoes
having shown them. `Lüthy + Stocker AG` with four branch addresses stays four organizations for the
same reason, and stripping the legal form (`Example` / `Example GmbH`) is an opt-in checkbox rather
than a default: on the real file it merged two pairs correctly and would merge two different
companies sharing a building.

**The key is derived, never stored**, which is the load-bearing decision. `for_organization()`
re-derives it from an organization's own columns, so a second run of the same file creates nothing,
a follow-up export joins the organizations the first one made, and an import finds an organization
that registered on the site by hand. It costs no column — so no schema change and no
`WOAP_DB_VERSION` bump — and `ImportTest::testAStoredOrganizationAnswersWithTheKeyItWasCreatedFrom`
is the round trip that keeps the two halves honest.

- **Nothing refuses a row but a missing email address.** A postcode WooCommerce rejects, a country
  it does not know, an address with no street — all imported, all reported. A customer whose
  postcode is wrong can sign in and be repaired; a customer who was never imported has to be told to
  register again, and some of them will not. `testOnlyAMissingEmailAddressMakesARowUnimportable`
  asserts the rule from the other side, over every row of the fixture.
- **What is wrong with a file is counted by problem, not by row**, and that came out of running the
  real export. Its 647 rows carried 54 postcodes WooCommerce would not accept — 54 lines to read one
  at a time, four when counted by message, and those four show at a glance that they are nearly all
  German postcodes on rows labelled Switzerland. The same file against a shop still on WooCommerce's
  defaults adds 581 more, one per row with no phone number, because **WooCommerce defaults the
  checkout phone field to required** and `AddressFields` reads the shop's own answer. That is one
  setting to reconsider before importing rather than 581 records to repair, and as rows in a list it
  would have buried the 54 entirely. A warning must therefore never carry the row's own data in it —
  that would be a tally of one, every time.

  The corollary is a trap worth naming: **the suite's database is not the shop's.** It is a fresh
  WooCommerce install on stock settings, so a required-field warning seen under test may be saying
  something about WooCommerce's defaults rather than about the file. Check a settings-dependent
  figure against the real site with `./bin/wp` before reporting it as a property of the data.
- **The preview is the importer with its writes switched off**, not a second pass that agrees with
  it. `Importer` takes a `$dry_run` flag and hands out negative pseudo-IDs for the rows it decides
  to create; everything else — the grouping, the roles, the location de-duplication, the reasons a
  row is skipped — runs identically. `testThePreviewAgreesWithTheImport` is the assertion that makes
  the preview worth showing, and it caught two real divergences: a `> 0` test that read every
  pseudo-ID as "no organization", and a created/joined flag read from the run rather than the row.
- **Nothing sends email.** Not the shop's new-account mail, not `NewOrganizationEmail` — the import
  fires `woo_org_accounts_organization_imported` rather than `..._registered`, because a migration
  is not six hundred sign-ups. Accounts are created with a random password nobody holds and the
  shop invites people to set one in its own time; `pre_wp_mail` is short-circuited for the length of
  each batch as well, because another plugin may be listening for new users.
- **A user who is already a member of an organization is never moved.** The membership row is what
  every order they have placed is scoped by. An address belonging to the organization the row maps
  to has been imported already; one belonging to a *different* organization is the case an import
  cannot answer, and it is reported rather than guessed at. An existing WordPress user with no
  membership is joined up instead of duplicated — but **anybody holding `manage_woocommerce` keeps
  their role**, because `set_role()` replaces every role a user holds and demoting an administrator
  is how an import locks the shop's own staff out of wp-admin.
- **An organization is active if any of its rows is.** The status arrives once per person and the
  copies disagree — one employee who has left, one who has not. Taking the first row's answer
  suspends a working customer because a former colleague's login was closed. Only organizations the
  run created are corrected this way; one that was already on the site has a status somebody chose.
- **The location key is the address and the company at it, not the contact name or the phone.**
  Those say who to ask for when the van arrives, not where it goes. A flat export names a different
  employee as the contact on every row, so keying on the name turned one loading bay into one
  location per employee — an identical address repeated down the checkout's delivery selector with
  nothing to tell the copies apart. `address_2` stays in the key, which is where a floor or a unit
  number lives.
- **Every organization ends up with at least one location**, falling back to its billing address,
  because an organization with none cannot be shipped to and therefore cannot check out at all.
- **The export is the most sensitive file a shop owns and the uploads directory is public.** A
  random 32-character filename, `.htaccess` plus `index.html`, deletion the moment the last batch
  finishes, and a sweep of anything a closed browser left behind after a day. The report is written
  beside it and never linked: downloading it goes through an admin-post handler with a capability
  check and a nonce. The report is also where the source shop's customer number lives — this plugin
  has no column for one and inventing one would be a field with no destination, but it is what the
  two systems are reconciled by.
- **State lives in an unautoloaded option, not a transient**, because a transient is allowed to
  vanish and an import whose state disappeared halfway through is six hundred half-imported
  customers with no screen able to say which. One import at a time: two running at once would race
  on the very lookup the grouping depends on.
- **Batches are nonced POSTs to `admin-post.php`.** The objection recorded above is about *frontend*
  handlers, where WooCommerce loads nothing; in wp-admin it is the right door. Each batch redirects
  back to the screen, so progress survives a host that kills a long request, and a small inline
  script clicks the button a person would otherwise click — with the script blocked the import still
  finishes, one press per batch.

`tests/fixtures/customers.csv` is synthetic. Every shape in it was found in the real export and none
of the data is anybody's: **never commit a customer file**, the same rule as a credential.

### Approval gates two different things, and they are two settings

`require_approval` decides whether a new registration may **order**; `require_approval_to_sign_in`
decides whether its members may **sign in at all**. The second is off by default — letting a pending
customer sign in and look around while they wait is a perfectly reasonable shop — and it is
deliberately not conditional on the first, because an organization also becomes unapproved by being
suspended or rejected, which is worth locking out on a shop that approves nothing.

`LoginGate` is the whole of the second rule, and it takes three hooks rather than one, because a
sign-in happens in three different places:

- **`authenticate`, at priority 100.** Last, so nothing can answer after it — core resolves the
  credentials between 20 and 30, and `wp_authenticate_cookie()` at 30 could otherwise overrule a
  refusal. Being on that filter covers My Account, wp-login.php, XML-RPC and application passwords
  at once. A `WP_Error` already on its way out is passed straight through, so a wrong password still
  reports a wrong password.
- **`init`, per request.** An organization stops being approved by being suspended, long after
  anybody signed in; a rule applied only at sign-in would leave whoever was already in exactly where
  they were. The session is ended and the redirect carries the reason, so the login screen can say
  what happened rather than appearing for no visible cause.
- **`Registration`, directly.** Registration sets the auth cookie itself and never passes through
  `authenticate`, so it asks `LoginGate::reason_for_status()` and renders
  `registration/pending-approval.php` instead of signing the new admin in. Signing them in and
  logging them out again on their next request would be a worse way of saying *we are reviewing
  this*. The invitation flow asks the same question, because an organization that is itself pending
  can still have sent an invitation.

**Two people are never refused.** Anybody holding `manage_woocommerce`, because an administrator who
joined an organization to see what a customer sees must not be able to lock themselves out by
suspending it — the screen that would let them back in is behind the door that just shut. And anybody
with no membership at all: an author, a subscriber, the site owner. They have no organization to
approve, and refusing everybody without one would close the site to its own staff.

## Working agreement

- Run `composer lint` before calling any change done. The hook covers files you edit; the full run
  catches everything else.
- Add or update a test with each behaviour change — `composer test`.
- Verify against the real site (`./bin/wp`, http://testshop.local) rather than reasoning about what
  WooCommerce would do.
- Bump the version in both the `woo-organization-accounts-pro.php` header and the `WOAP_VERSION`
  constant together — `bin/check-version.sh` fails the build when they drift apart.
- Bump `WOAP_DB_VERSION` whenever `Install::schema()` changes, or existing sites keep the old tables.

## Languages — English and German

The plugin ships English (the source strings) and German, and German is a requirement rather than a
courtesy: `tests/I18nTest.php` fails the build on a string that is in the catalogue but untranslated.
WordPress falls back to English silently, one label at a time, so nothing else would notice.

- **Two German catalogues, not one.** WordPress treats `de_DE` (informal, *du* — the register
  WordPress core itself uses) and `de_DE_formal` (*Sie*) as unrelated locales, so both are
  maintained. Only the strings that address the reader differ between them.
- **Every other German locale is mapped**, by `Plugin::map_german_locale()` on
  `load_textdomain_mofile`. `de_AT`, `de_CH` and `de_CH_informal` have no catalogue of their own and
  WordPress does not fall back between German locales, so without this an Austrian shop reads an
  English admin screen. The filter only redirects a *missing* German catalogue, so a translation
  someone drops into `wp-content/languages/plugins` still wins.
- **Both organization modes need translating.** Every mode-dependent noun exists twice in the source
  and only one of the two is ever on screen, which makes them the strings most likely to be
  half-translated. `I18nTest::test_both_modes_are_translated` covers both.
- **`.mo` and `.l10n.php` are both committed and both shipped.** WordPress 6.5 and newer load the
  PHP file in preference to the `.mo`. The `.po` sources are the input and are left out of the build.
- **Regenerate with `composer i18n`** after adding or changing any translatable string: it
  re-extracts the POT, merges it into each `.po` (keeping existing translations, marking changed
  ones fuzzy) and recompiles. A fuzzy entry counts as untranslated and fails the tests.
- **`composer i18n:check`** asserts the POT still lists every string in the source. It compares the
  *set of strings*, not the files, so moving a function does not fail the build.
- Strings for the block checkout script are registered with `wp_set_script_translations()`.

## Continuous integration and releases

`.github/workflows/ci.yml` runs on every push to `main` and every pull request:

- **Coding standards** — `composer validate --strict`, `bin/check-version.sh`, `composer lint`, and
  `node --check` over `assets/js`.
- **Translations** — `bin/check-translations.sh`, which needs wp-cli and so runs in its own job.
  Whether the strings are *translated* is asserted by the test suite instead.
- **Dependency audit** — `composer audit --locked`.
- **Tests** — the full suite on PHP 8.2, 8.3 and 8.4. 8.2 is the floor and what the site runs.
- **Coverage** — one more run under pcov, published as a job summary and an artifact.
- **Build** — `bin/build-zip.sh`, so every pull request proves the release artefact still builds.

**CI cannot use `bin/install-wp-tests.sh`** — it resolves everything from Local's `sites.json`.
`bin/install-wp-tests-ci.sh` is the runner's equivalent. **It symlinks the checkout into
`wp-content/plugins/woo-organization-accounts-pro`, and that link is load-bearing.**
`tests/bootstrap.php` loads the plugin from `WP_PLUGIN_DIR` and calls `wp_register_plugin_realpath()`
first, which is how WordPress itself loads a symlinked plugin and the only way `plugin_basename()`
shortens to the plugin slug. Everything keyed on that slug behaves differently when it does not:
`FeaturesUtil::declare_compatibility()` records HPOS support under an absolute path, and
`load_plugin_textdomain()` registers a languages directory that does not exist.

Releases are cut by pushing a `v*` tag (`.github/workflows/release.yml`): CI runs against the tagged
commit first, `bin/check-version.sh <tag>` asserts the tag and the two version declarations agree,
and `bin/build-zip.sh` produces the zip, its `.sha256` and `dist/update.json`, which `gh release
create` publishes. A tag containing a hyphen (`v0.2.0-rc.1`) is published as a pre-release.

The zip carries only what WordPress runs: the main file, `includes/`, `assets/`, `templates/`,
`languages/`, `uninstall.php` and a `--no-dev` `vendor/`.

## Updates from GitHub

The plugin is not in the WordPress.org directory, so nothing would otherwise tell an installed copy
that a newer version exists. `Updates\Updater` closes that gap through the **`Update URI` header**
and core's **`update_plugins_{$hostname}` filter** — the mechanism WordPress added in 5.8 for
exactly this — rather than a bundled update-checker library.

- **An answer is returned even when the installed version is current**, which is what makes the
  auto-update toggle appear: the plugins screen decides a plugin supports updates by finding it in
  `response` *or* `no_update`.
- **`package` is the built release asset**, which is what lets core install unattended.
- **The metadata comes from `update.json` published beside the zip**, at
  `…/releases/latest/download/update.json`, not from `api.github.com` — which is rate limited to 60
  requests an hour per IP and carries none of the plugin's requirements. **A release published
  without `update.json` is invisible to every installed copy.**
- **A package pointing anywhere but this repository's releases is discarded.**
- **The updater is registered before the WooCommerce and HPOS gates**, in the main file rather than
  in `Plugin::init()`. An update is often exactly what fixes a plugin sitting inert behind one.
- **A failed check reports nothing**, which reads as "updates not available" rather than "up to
  date". Failure is cached for an hour and success for six; **Check for updates** clears both.

## Dependency versions — do not "upgrade" these

Two pins look outdated and are not.

- **PHP_CodeSniffer stays on 3.x.** 4.0 is released, but `wp-coding-standards/wpcs` 3.4.1 requires
  `^3.13.5`. Requiring `woocommerce/woocommerce-sniffs ^2.0` resolves the whole standards stack
  correctly; do not pin PHPCS directly.
- **PHPUnit stays on 9.x.** WordPress's own test library calls
  `PHPUnit\Util\Test::parseTestMethodAnnotations()` and `$this->getName( false )`, both removed in
  PHPUnit 10. This is a WordPress constraint, not a polyfills one.
- `config.platform.php` is pinned to `8.2.29` so Composer resolves for the site's runtime rather
  than the host's PHP 8.5.
