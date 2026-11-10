# Building the plugin

The distribution includes the readable JavaScript source in `src/`, the locked
npm dependencies, and the webpack configuration used to build `build/`.
The Node.js version is declared in `package.json`.

From the plugin directory, install dependencies and compile the assets:

```sh
npm ci
npm run build
```

These commands work from an installed or unpacked plugin package without Git.
Packaging and release commands (`npm run zip`, `npm run export`, and
`npm run release:prepare`) require a full source Git checkout with `.distignore`
at its root. They use the tracked files and distribution manifest to build a
release ZIP; an installed plugin package is not a release checkout.

`node_modules/` contains development tools and must not be deployed. The
WordPress plugin runs from the bundled PHP, JavaScript, CSS and translations;
merchants do not need Node.js to install or use it.

## Release notes

`readme.txt` is the source of truth for release notes. The latest published entry
uses `= VERSION - YYYY-MM-DD =` with its publication date. Generate the
WooCommerce Marketplace's standalone changelog from it:

```sh
node scripts/changelog.js
node scripts/changelog.js --check
```

`changelog.txt` contains dated releases. Older entries whose publication dates
were not recorded remain in `readme.txt`. An optional `= Unreleased =` entry is
kept out of the published changelog until a release is prepared. Do not edit the
generated file directly.
