const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const ROOT = path.resolve(__dirname, '..');
const SLUG = 'sumup-payment-gateway-for-woocommerce';
const PLUGIN = `${SLUG}.php`;
const REQUIRED = [
  PLUGIN,
  'readme.txt',
  'changelog.txt',
  'LICENSE',
  'CONTRIBUTING.md',
  'src/BUILDING.md',
  'package.json',
  'package-lock.json',
  'webpack.config.js',
  'archiver.js',
  'scripts/release.js',
  'scripts/changelog.js',
  'scripts/verify-release-zip.py',
  'src/index.js',
  ...['index', 'settings', 'sumup-gateway', 'sumup-process-checkout'].flatMap(
    (name) => [`build/${name}.js`, `build/${name}.asset.php`],
  ),
  ...['index', 'settings', 'sumup-process-checkout'].flatMap((name) => [
    `build/${name}.css`,
    `build/${name}-rtl.css`,
  ]),
];

function read(root, file) {
  return fs.readFileSync(path.join(root, file), 'utf8');
}

function git(root, args, options = {}) {
  return execFileSync('git', ['-c', 'core.fsmonitor=false', ...args], {
    cwd: root,
    ...options,
  });
}

function requireSourceCheckout(root) {
  const error = new Error(
    'Release commands require a full source Git checkout containing .distignore at its root. Rebuild an installed or downloaded plugin package with npm ci and npm run build.',
  );
  if (!fs.existsSync(path.join(root, '.distignore'))) throw error;
  let repositoryRoot;
  try {
    repositoryRoot = git(root, ['rev-parse', '--show-toplevel'], {
      encoding: 'utf8',
      stdio: 'pipe',
    }).trim();
  } catch {
    throw error;
  }
  if (fs.realpathSync(repositoryRoot) !== fs.realpathSync(root)) throw error;
}

function match(text, pattern, label) {
  const value = text.match(pattern)?.[1];
  if (!value) throw new Error(`Missing ${label}.`);
  return value;
}

function validVersion(version) {
  if (!/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/.test(version)) {
    throw new Error(
      `Expected a stable version in MAJOR.MINOR.PATCH format, got ${version}.`,
    );
  }
  return version.split('.').map(BigInt);
}

function metadata(root) {
  const pkg = JSON.parse(read(root, 'package.json'));
  const lock = JSON.parse(read(root, 'package-lock.json'));
  const php = read(root, PLUGIN);
  const readme = read(root, 'readme.txt');
  if (
    pkg.name !== SLUG ||
    lock.name !== SLUG ||
    lock.packages?.['']?.name !== SLUG
  ) {
    throw new Error(`Package names must match ${SLUG}.`);
  }
  validVersion(pkg.version);
  const versions = {
    'package.json': pkg.version,
    'package-lock.json': lock.version,
    'package-lock.json packages[""]': lock.packages?.['']?.version,
    'Plugin Version': match(php, /^ \* Version:\s*(\S+)/m, 'plugin Version'),
    WC_SUMUP_VERSION: match(
      php,
      /define\(\s*'WC_SUMUP_VERSION',\s*'([^']+)'\s*\)/,
      'WC_SUMUP_VERSION',
    ),
    'Stable tag': match(readme, /^Stable tag:\s*(\S+)/m, 'Stable tag'),
  };
  for (const [label, version] of Object.entries(versions)) {
    if (version !== pkg.version)
      throw new Error(`${label} is ${version}; expected ${pkg.version}.`);
  }
  return { name: pkg.name, version: pkg.version, pkg, lock, php, readme };
}

function notesSection(readme, title) {
  const marker = `== ${title} ==`;
  const start = readme.indexOf(marker);
  if (start === -1) throw new Error(`Missing ${title} section in readme.txt.`);
  const content = readme
    .slice(start + marker.length)
    .split(/^== [^=]+ ==\s*$/m)[0];
  const entries = [
    ...content.matchAll(
      /^= (Unreleased|\d+\.\d+\.\d+)(?: - (\d{4}-\d{2}-\d{2}))? =[^\S\r\n]*\r?$/gm,
    ),
  ];
  if (!entries.length) throw new Error(`Missing ${title} release notes.`);
  return { start: start + marker.length, entries, content };
}

function checkProject(root = ROOT, tag) {
  const info = metadata(root);
  for (const title of ['Changelog', 'Upgrade Notice']) {
    const { entries, content } = notesSection(info.readme, title);
    if (tag && entries.some((entry) => entry[1] === 'Unreleased'))
      throw new Error(
        `A tagged release cannot contain Unreleased ${title} notes.`,
      );
    const latest = entries.find((entry) => entry[1] !== 'Unreleased');
    if (latest?.[1] !== info.version)
      throw new Error(`Latest ${title} must describe ${info.version}.`);
    const next = entries[entries.indexOf(latest) + 1];
    const notes = content
      .slice(latest.index + latest[0].length, next?.index)
      .trim();
    if (!notes)
      throw new Error(`${title} notes for ${info.version} are empty.`);
  }
  execFileSync(process.execPath, ['scripts/changelog.js', '--check'], {
    cwd: root,
    stdio: 'pipe',
  });
  if (tag) {
    requireSourceCheckout(root);
    if (tag !== info.version)
      throw new Error(`Tag ${tag} does not match version ${info.version}.`);
    const head = git(root, ['rev-parse', 'HEAD'], {
      encoding: 'utf8',
    }).trim();
    const tagged = git(root, ['rev-parse', `refs/tags/${tag}^{commit}`], {
      encoding: 'utf8',
    }).trim();
    if (tagged !== head)
      throw new Error('Release tag must point to the checked-out commit.');
    git(
      root,
      ['merge-base', '--is-ancestor', 'HEAD', 'refs/remotes/origin/main'],
      { stdio: 'pipe' },
    );
  }
  return info;
}

function prepareRelease(
  root,
  version,
  date = new Date().toISOString().slice(0, 10),
) {
  requireSourceCheckout(root);
  const next = validVersion(version);
  const info = metadata(root);
  const previous = validVersion(info.version);
  const changed = next.findIndex((value, index) => value !== previous[index]);
  if (changed === -1 || next[changed] < previous[changed])
    throw new Error('New version must be greater than the current version.');
  const tags = git(root, ['tag', '--list', version], {
    encoding: 'utf8',
  });
  if (tags.trim()) throw new Error(`Tag ${version} already exists.`);
  let readme = info.readme;
  for (const title of ['Changelog', 'Upgrade Notice']) {
    const { start, entries, content } = notesSection(readme, title);
    const entry = entries[0];
    if (entry[1] !== 'Unreleased' && entry[1] !== version)
      throw new Error(
        `Add real '= Unreleased =' notes at the top of ${title} before preparing a release.`,
      );
    const notes = content
      .slice(entry.index + entry[0].length, entries[1]?.index)
      .trim();
    if (!notes)
      throw new Error(
        `${title} needs release notes before preparing a release.`,
      );
    const heading =
      title === 'Changelog'
        ? `= ${version} - ${entry[2] || date} =`
        : `= ${version} =`;
    const position = start + entry.index;
    readme =
      readme.slice(0, position) +
      heading +
      readme.slice(position + entry[0].trimEnd().length);
  }
  const php = info.php
    .replace(/^( \* Version:\s*)\S+/m, `$1${version}`)
    .replace(/(define\(\s*'WC_SUMUP_VERSION',\s*')[^']+/, `$1${version}`);
  readme = readme.replace(/^(Stable tag:\s*)\S+/m, `$1${version}`);
  info.pkg.version = version;
  info.lock.version = version;
  info.lock.packages[''].version = version;
  const updates = new Map([
    [PLUGIN, php],
    ['readme.txt', readme],
    ['package.json', `${JSON.stringify(info.pkg, null, 2)}\n`],
    ['package-lock.json', `${JSON.stringify(info.lock, null, 2)}\n`],
  ]);
  const originals = new Map(
    [...updates.keys(), 'changelog.txt'].map((file) => [
      file,
      fs.existsSync(path.join(root, file))
        ? fs.readFileSync(path.join(root, file))
        : null,
    ]),
  );
  try {
    for (const [file, content] of updates)
      fs.writeFileSync(path.join(root, file), content);
    execFileSync(process.execPath, ['scripts/changelog.js'], {
      cwd: root,
      stdio: 'pipe',
    });
    checkProject(root);
  } catch (error) {
    for (const [file, content] of originals) {
      if (content === null) fs.rmSync(path.join(root, file), { force: true });
      else fs.writeFileSync(path.join(root, file), content);
    }
    throw error;
  }
}

function excluded(file, patterns) {
  return patterns.some((pattern) => {
    const directory = pattern.endsWith('/');
    const anchored =
      pattern.startsWith('/') || pattern.replace(/\/$/, '').includes('/');
    const body = pattern.replace(/^\//, '').replace(/\/$/, '');
    if (body.includes('!') || body.includes('[') || body.includes('\\'))
      throw new Error(`Unsupported .distignore pattern: ${pattern}`);
    const escaped = body
      .replace(/[.+^${}()|[\]\\]/g, '\\$&')
      .replace(/\*\*/g, '\u0000')
      .replace(/\*/g, '[^/]*')
      .replace(/\?/g, '[^/]')
      .replace(/\u0000/g, '.*');
    return new RegExp(
      `${anchored ? '^' : '(^|/)'}${escaped}${directory ? '/' : '($|/)'}`,
    ).test(file);
  });
}

function distributionFiles(root = ROOT) {
  requireSourceCheckout(root);
  const patterns = read(root, '.distignore')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line && !line.startsWith('#'));
  const tracked = git(root, ['ls-files', '-z'], {
    encoding: 'utf8',
  })
    .split('\0')
    .filter(Boolean);
  const files = tracked.filter((file) => !excluded(file, patterns)).sort();
  for (const file of fs.readdirSync(path.join(root, 'build'), {
    recursive: true,
  })) {
    const relative = `build/${file}`;
    if (
      fs.lstatSync(path.join(root, relative)).isFile() &&
      !tracked.includes(relative)
    ) {
      throw new Error(`Generated build output must be tracked: ${relative}`);
    }
  }
  for (const file of files) {
    if (!fs.lstatSync(path.join(root, file)).isFile())
      throw new Error(`Release entries must be regular files: ${file}`);
    if (
      file
        .split('/')
        .some(
          (part) =>
            part === '..' ||
            part === '.git' ||
            part === 'node_modules' ||
            part === 'vendor',
        ) ||
      /(^|\/)\.env(?:\.|$)/.test(file)
    )
      throw new Error(`Unsafe release entry: ${file}`);
  }
  for (const required of REQUIRED) {
    if (!files.includes(required))
      throw new Error(
        `Required distribution file is missing or excluded: ${required}`,
      );
  }
  return files;
}

function validateArchive(root, filename, destination) {
  const info = checkProject(root);
  const files = distributionFiles(root);
  const args = [path.join(__dirname, 'verify-release-zip.py'), root, filename];
  if (destination) args.push(destination);
  execFileSync('python3', args, {
    cwd: root,
    input: JSON.stringify({ slug: info.name, files }),
    stdio: ['pipe', 'pipe', 'pipe'],
  });
}

function main(args) {
  const [command, value, destination] = args;
  if (command === 'prepare' && value && args.length === 2) {
    prepareRelease(ROOT, value);
    console.log(
      `Prepared ${value}. Review and commit the release changes before tagging.`,
    );
  } else if (
    command === 'check' &&
    (!value || value === '--tag') &&
    args.length === (value ? 3 : 1)
  ) {
    console.log(
      `Release metadata verified for ${checkProject(ROOT, value ? destination : undefined).version}.`,
    );
  } else if (
    (command === 'check-archive' || command === 'stage-archive') &&
    value
  ) {
    if (command === 'stage-archive' && !destination)
      throw new Error('stage-archive requires a new destination directory.');
    validateArchive(
      ROOT,
      value,
      command === 'stage-archive' ? destination : undefined,
    );
    console.log(`Release ZIP verified: ${value}`);
  } else {
    throw new Error(
      'Usage: release.js check [--tag VERSION] | prepare VERSION | check-archive ZIP | stage-archive ZIP NEW_DIRECTORY',
    );
  }
}

if (require.main === module) {
  try {
    main(process.argv.slice(2));
  } catch (error) {
    console.error(error.message);
    if (error.stderr) console.error(error.stderr.toString().trim());
    process.exitCode = 1;
  }
}

module.exports = {
  metadata,
  checkProject,
  prepareRelease,
  excluded,
  distributionFiles,
  validateArchive,
  REQUIRED,
};
