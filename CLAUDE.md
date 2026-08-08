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

**High-Performance Order Storage is a hard requirement.** Declaring compatibility is not enough:
`bootstrap()` calls `OrderUtil::custom_orders_table_usage_is_enabled()` and, when HPOS is off,
registers an admin notice and returns without initialising. Every order this plugin writes carries
the organization it belongs to, and every organization order list reads it back; against the legacy
post-based store those queries would silently scope to the wrong data rather than fail loudly.

Because HPOS is guaranteed, order code uses the CRUD APIs directly and never carries a legacy
fallback. When you bump these floors, update the plugin header, `WOAP_MIN_WC_VERSION`,
`composer.json`, and the `testVersion` / `minimum_wp_version` values in `phpcs.xml.dist` together.

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
  returns a 404 — after the write has landed. `AccountHandlersTest` asserts no template posts a
  field named after a query variable. The only exceptions are the WooCommerce address blocks,
  which must keep `billing_` and `shipping_`.
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

### Address fields

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
- **A rejected submission is handed back, not redirected away.** Losing a twelve-field address to
  one mistyped postcode is not an acceptable way to report an error. Every field that was rejected
  is marked `woocommerce-invalid` with the reason under it, because a notice at the top of a
  fourteen-field form says *something* is wrong without saying where. The admin screen has to
  redirect — `admin-post.php` prints nothing — so it parks the whole submission in a one-minute
  transient and refills the form from it.

### Editing one of many

A screen that edits one row out of a list is its own screen, reached by a query argument
(`?woap_location=<id>`, or `new`), never a form underneath the list. Three things go wrong with the
form-under-the-list shape, and the locations screen had all of them: you scroll past everything else
to reach it, nothing says which row is open, and — the real bug — the row being edited lives only in
a query argument the form does not post back, so a rejected submission returns as a blank *add*
form and saving again creates a duplicate instead of correcting the original. The form posts to its
own URL, argument included.

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
join form instead of the registration form when the request carries a token.

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
