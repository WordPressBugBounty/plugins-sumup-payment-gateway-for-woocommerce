# Contributing

## Dependency Updates

This plugin has two different dependency classes:

1. Development-only tooling that runs in local environments and CI.
2. JavaScript packages that are coupled to the WordPress and WooCommerce versions we support in production.

### Safe

These dependencies do not define the plugin runtime contract for merchants. They can be updated through normal Dependabot PRs as long as CI stays green:

- GitHub Actions in `.github/workflows/`
- Composer `require-dev` packages in [composer.json](./composer.json)
- npm tooling and standalone build/runtime utilities such as `@biomejs/biome`, `archiver`, and `axios` in [package.json](./package.json)

### Manual

The following npm packages must not be auto-updated by Dependabot:

- `@wordpress/scripts`
- `react`

Keep these aligned with the oldest WordPress and WooCommerce versions that we support, because they influence the block checkout integration and the JavaScript that is compiled against WordPress-provided packages.

Before bumping one of them manually:

1. Check the supported version floor declared in [sumup-payment-gateway-for-woocommerce.php](./sumup-payment-gateway-for-woocommerce.php) and [readme.txt](./readme.txt).
2. Confirm the newer package version does not require WordPress or WooCommerce APIs that are missing from the oldest supported releases.
3. Rebuild the assets and test both classic checkout and block checkout flows.

## Translations

Plugin text uses the `sumup-payment-gateway-for-woocommerce` gettext domain.
PHP supplies translated checkout text to JavaScript, so no separate JavaScript
catalogs are needed. Select the store language in **Settings > General > Site
Language**. WooCommerce's language packs and this plugin's translations are
separate.

Install GNU gettext (`brew install gettext` or `apt-get install gettext`) and
Python 3, then set up the development tools:

```bash
python3 -m venv /tmp/sumup-i18n
. /tmp/sumup-i18n/bin/activate
pip install -r scripts/i18n-requirements.txt
npm run i18n:update
```

Edit the `.po` files in `languages/`, then run `npm run i18n:update` again to
compile the `.mo` files. Commit the PHP changes, the single generated `.pot`,
and the updated `.po` and `.mo` files together. Do not edit `.pot` or `.mo` files
by hand. Use WordPress locale identifiers in filenames and headers, such as
`el`, `et`, `fi`, `lv`, `hr`, and `es_CO`.

Run `npm run i18n:check` before submitting. CI checks source extraction,
translation completeness, fuzzy entries, printf placeholders, HTML structure,
and generated file freshness. It does not assess linguistic accuracy; new
translations should receive native-speaker review before release. To start a
new catalog, copy a same-language `.po` file to the new WordPress locale filename,
or run `python3 scripts/i18n.py update --add-locale hr` for a new language. Add
its plural rule to `scripts/i18n.py` if needed.

The SumUp widget provides its own translations. `WC_Sumup_I18n` maps WordPress
locales to widget locales, including Colombian Spanish to `es-CL`. The widget
currently falls back to English for Croatian, Romanian, and unsupported
languages; plugin text still uses the selected WordPress language.

## Release

Prepare each release in a pull request. Add real `= Unreleased =` entries at the
start of both the Changelog and Upgrade Notice sections in `readme.txt`, then run:

```bash
npm ci
npm run release:prepare -- 2.18.0
npm run zip
```

Use the intended new version instead of `2.18.0`. The prepare command updates the
PHP header and version constant, readme stable tag, npm package and lockfile,
and generates `changelog.txt`. It dates the new changelog entry using the current
UTC date. It refuses a version that is not newer or already has a local Git tag.
Review the release notes and generated changes before committing them.

Run packaging and release commands (`npm run zip`, `npm run export`, and
`npm run release:prepare`) from a full source Git checkout with `.distignore`
at its root. Installed or unpacked plugin packages support `npm ci` and
`npm run build` for recompiling assets; release commands also need the
repository's Git metadata and distribution manifest.
Release tooling requires Node.js from `.nvmrc` and Python 3 (standard library
only). `npm run zip` always rebuilds JavaScript and CSS, checks version agreement and
changelog freshness, and creates `sumup-payment-gateway-for-woocommerce-VERSION.zip`
in the repository root. Only Git-tracked files allowed by `.distignore` are
included; stage new distribution files before running it. The ZIP is validated
against the checkout byte for byte, including bundled source and build tools.
A previous ZIP does not prove that a later checkout is ready to release.

Run the normal PHP, JavaScript, translation and payment checks, including classic
and Blocks checkout. Review any rebuilt assets and commit them with the release.
The Release package workflow independently rebuilds from the locked dependencies,
fails if committed assets or generated changelog are stale, runs release-tool
regressions, and uploads the validated ZIP for each PR and main-branch push.

After the release PR is approved, CI passes and the release commit is merged:

```bash
git fetch origin main --tags
git switch main
git pull --ff-only origin main
npm run release:check
git tag 2.18.0
git push origin 2.18.0
```

Push a bare numeric version tag, without a `v` prefix, at the intended release
commit. The WordPress deployment workflow verifies that the tag matches every
version field, points to the checked-out commit, and belongs to `origin/main`.
It rebuilds and validates the ZIP before any WordPress credentials are used,
then deploys the exact unpacked ZIP through the deploy action's `BUILD_DIR`.
Other tag names and releases containing Unreleased notes fail validation.
The exact commit must also have successful main-branch runs of CI, Release
package and Submission checks. If they are still running, re-run the deployment
workflow after all three pass.

The workflow requires the existing `SVN_USERNAME` and `SVN_PASSWORD` repository
secrets and WordPress.org approval for the plugin. It does not create a release
tag or resolve directory review requirements on your behalf. Run the Marketplace
submission checks separately before submitting the same ZIP to WooCommerce.

## Checkout testing

A successful onboarding validation enables payments. Rechecking an already
connected account preserves its explicit enabled/disabled setting.

Use the payment acceptance checklist in [SUBMISSION.md](./SUBMISSION.md#payment-acceptance)
for release and WooCommerce Marketplace testing.

Classic and Blocks checkout use the SumUp payment widget. Apple Pay and Google
Pay remain in the widget and honor their existing settings. Customers provide
billing and shipping details through WooCommerce; wallets do not populate them.
Check both checkout types with a stored legacy `express_checkout=yes` option:
there must be no separate wallet shortcut or express payment registration.

## Distribution contents

`.distignore` controls both local release ZIPs (`npm run zip`) and WordPress.org
deployments. Keep development fixtures and local environments excluded there.
