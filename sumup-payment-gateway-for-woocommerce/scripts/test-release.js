const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { createHash } = require('node:crypto');
const { test } = require('node:test');
const { createArchive } = require('../archiver');
const {
  checkProject,
  prepareRelease,
  excluded,
  distributionFiles,
  validateArchive,
  REQUIRED,
} = require('./release');

const SLUG = 'sumup-payment-gateway-for-woocommerce';
const VERSION = '2.17.1';

function git(root, ...args) {
  return execFileSync('git', ['-c', 'core.fsmonitor=false', ...args], {
    cwd: root,
    encoding: 'utf8',
    stdio: 'pipe',
  }).trim();
}

function put(root, file, text) {
  const target = path.join(root, file);
  fs.mkdirSync(path.dirname(target), { recursive: true });
  fs.writeFileSync(target, text);
}

function fixture(t) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'sumup-release-test-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  for (const file of REQUIRED) put(root, file, `Fixture ${file}\n`);
  put(root, 'package.json', JSON.stringify({ name: SLUG, version: VERSION }));
  put(
    root,
    'package-lock.json',
    JSON.stringify({
      name: SLUG,
      version: VERSION,
      packages: { '': { name: SLUG, version: VERSION } },
    }),
  );
  put(
    root,
    `${SLUG}.php`,
    `<?php\n/**\n * Version: ${VERSION}\n */\ndefine( 'WC_SUMUP_VERSION', '${VERSION}' );\n`,
  );
  put(
    root,
    'readme.txt',
    `=== SumUp ===\r\nStable tag: ${VERSION}\r\n\r\n== Changelog ==\r\n= ${VERSION} - 2026-07-06 =\r\n* Fixed payments.\r\n\r\n== Upgrade Notice ==\r\n= ${VERSION} =\r\nUpgrade for the payment fix.\r\n`,
  );
  put(root, 'changelog.txt', `version ${VERSION}\n`);
  put(
    root,
    'scripts/changelog.js',
    `const fs = require('node:fs');
const version = fs.readFileSync('readme.txt', 'utf8').match(/^Stable tag: (\\S+)/m)[1];
const output = 'version ' + version + '\\n';
if (process.argv.includes('--check')) {
  if (fs.readFileSync('changelog.txt', 'utf8') !== output) throw Error('Changelog is stale.');
} else fs.writeFileSync('changelog.txt', output);
`,
  );
  put(
    root,
    '.distignore',
    '.git/\n.distignore\n.env\n.env.*\nnode_modules/\nvendor/\ntests/\nexample/\nscripts/*.php\n*.zip\n*.partial\n',
  );
  put(root, 'tests/fixture.txt', 'Not for distribution.');
  put(root, 'scripts/php-lint.php', '<?php // CLI helper.');
  put(root, '.env.private', 'Not for distribution.');
  git(root, 'init', '-q');
  git(root, 'add', '-f', '.');
  git(
    root,
    '-c',
    'user.name=Release Test',
    '-c',
    'user.email=release@example.test',
    'commit',
    '-qm',
    'Initial fixture',
  );
  return root;
}

function projectZip(root, extra = [], omit = []) {
  const filename = path.join(root, 'fixture.zip');
  const entries = distributionFiles(root)
    .filter((file) => !omit.includes(file))
    .map((file) => [
      `${SLUG}/${file}`,
      fs.readFileSync(path.join(root, file)).toString('base64'),
    ]);
  execFileSync(
    'python3',
    [
      '-c',
      `import base64,json,sys,warnings,zipfile
warnings.simplefilter('ignore', UserWarning)
with zipfile.ZipFile(sys.argv[1], 'w') as archive:
 for name,data,*mode in json.load(sys.stdin):
  info=zipfile.ZipInfo(name)
  if mode: info.external_attr=mode[0]<<16
  archive.writestr(info,base64.b64decode(data))
`,
      filename,
    ],
    {
      input: JSON.stringify([...entries, ...extra]),
      stdio: ['pipe', 'pipe', 'pipe'],
    },
  );
  return filename;
}

test('installed source packages explain the Git-checkout requirement before packaging or preparing', (t) => {
  const root = fixture(t);
  fs.rmSync(path.join(root, '.git'), { recursive: true });
  const requireCheckoutError = (action) => {
    assert.throws(action, (error) => {
      assert.match(error.message, /full source Git checkout/);
      assert.match(error.message, /npm ci.*npm run build/);
      assert.doesNotMatch(error.message, /ENOENT|fatal:/);
      assert.equal(error.stderr, undefined);
      return true;
    });
  };
  // An extracted source archive may retain .distignore but has no Git metadata.
  requireCheckoutError(() => distributionFiles(root));
  requireCheckoutError(() => prepareRelease(root, '2.17.2'));
  // An installed plugin package omits both the manifest and Git metadata.
  fs.unlinkSync(path.join(root, '.distignore'));
  requireCheckoutError(() => distributionFiles(root));
  requireCheckoutError(() => prepareRelease(root, '2.17.2'));
  assert.equal(checkProject(root).version, VERSION);
});

test('packaging rejects an installed plugin inside another Git repository', (t) => {
  const root = fixture(t);
  const installed = path.join(root, 'wp-content', 'plugins', SLUG);
  put(installed, '.distignore', '.git/\n');
  assert.throws(() => distributionFiles(installed), /full source Git checkout/);
});

test('glob literals escape regex syntax and reject unsupported backslashes', () => {
  for (const literal of [
    'file.txt',
    'a+b',
    'a^b',
    'a$b',
    'a{b}',
    'a(b)',
    'a|b',
    'a]b',
  ]) {
    assert.equal(excluded(literal, [literal]), true);
    assert.equal(excluded(`prefix${literal}`, [literal]), false);
  }
  for (const pattern of ['a\\.txt', 'a\\*', 'a[bc]', '!keep']) {
    assert.throws(() => excluded('a.txt', [pattern]), /Unsupported/);
  }
  assert.equal(excluded('build/index.js', ['build/*.js']), true);
  assert.equal(excluded('build/nested/index.js', ['build/*.js']), false);
  assert.equal(excluded('build/nested/index.js', ['build/**']), true);
  assert.equal(excluded('file1.txt', ['file?.txt']), true);
});

test('version fields, release notes and generated changelog must agree', (t) => {
  const root = fixture(t);
  assert.equal(checkProject(root).version, VERSION);
  const lock = JSON.parse(
    fs.readFileSync(path.join(root, 'package-lock.json')),
  );
  lock.packages[''].version = '2.17.0';
  put(root, 'package-lock.json', JSON.stringify(lock));
  assert.throws(() => checkProject(root), /expected 2\.17\.1/);
  lock.packages[''].version = VERSION;
  put(root, 'package-lock.json', JSON.stringify(lock));
  put(root, 'changelog.txt', 'Stale generated output.');
  assert.throws(() => checkProject(root), /Changelog is stale/);
});

test('prepare changes every version field and dates real notes while preserving CRLF', (t) => {
  const root = fixture(t);
  const readme = fs
    .readFileSync(path.join(root, 'readme.txt'), 'utf8')
    .replace(
      '== Changelog ==\r\n',
      '== Changelog ==\r\n= Unreleased =\r\n* Added an improvement.\r\n\r\n',
    )
    .replace(
      '== Upgrade Notice ==\r\n',
      '== Upgrade Notice ==\r\n= Unreleased =\r\nUpgrade for the improvement.\r\n\r\n',
    );
  put(root, 'readme.txt', readme);
  prepareRelease(root, '2.18.0', '2026-11-01');
  assert.equal(checkProject(root).version, '2.18.0');
  const prepared = fs.readFileSync(path.join(root, 'readme.txt'), 'utf8');
  assert.match(prepared, /= 2\.18\.0 - 2026-11-01 =\r\n/);
  assert.equal(prepared.replaceAll('\r\n', '').includes('\n'), false);
  assert.match(prepared, /= 2\.17\.1 - 2026-07-06 =/);
  assert.equal(
    fs.readFileSync(path.join(root, 'changelog.txt'), 'utf8'),
    'version 2.18.0\n',
  );
});

test('prepare rejects missing notes, invalid versions and existing tags without mutation', (t) => {
  const root = fixture(t);
  const original = fs.readFileSync(path.join(root, 'package.json'), 'utf8');
  assert.throws(() => prepareRelease(root, '2.17.2'), /Add real/);
  assert.throws(() => prepareRelease(root, VERSION), /greater/);
  assert.throws(() => prepareRelease(root, '2.16.9'), /greater/);
  assert.throws(() => prepareRelease(root, '2.17.2-beta'), /stable version/);
  git(root, 'tag', '2.17.2');
  assert.throws(() => prepareRelease(root, '2.17.2'), /already exists/);
  assert.equal(
    fs.readFileSync(path.join(root, 'package.json'), 'utf8'),
    original,
  );
});

test('tag validation requires version agreement, exact commit and main ancestry', (t) => {
  const root = fixture(t);
  git(root, 'tag', VERSION);
  git(root, 'update-ref', 'refs/remotes/origin/main', 'HEAD');
  assert.equal(checkProject(root, VERSION).version, VERSION);
  assert.throws(() => checkProject(root, '2.17.2'), /does not match/);
  put(root, 'new-file.txt', 'new commit');
  git(root, 'add', 'new-file.txt');
  git(
    root,
    '-c',
    'user.name=Release Test',
    '-c',
    'user.email=release@example.test',
    'commit',
    '-qm',
    'Second commit',
  );
  assert.throws(() => checkProject(root, VERSION), /checked-out commit/);
  git(root, 'tag', '-f', VERSION);
  assert.throws(() => checkProject(root, VERSION), /merge-base/);
});

test('prepare restores metadata and generated output when changelog generation fails', (t) => {
  const root = fixture(t);
  const readme = fs
    .readFileSync(path.join(root, 'readme.txt'), 'utf8')
    .replace(
      '== Changelog ==\r\n',
      '== Changelog ==\r\n= Unreleased =\r\n* Added an improvement.\r\n\r\n',
    )
    .replace(
      '== Upgrade Notice ==\r\n',
      '== Upgrade Notice ==\r\n= Unreleased =\r\nUpgrade for the improvement.\r\n\r\n',
    );
  put(root, 'readme.txt', readme);
  fs.appendFileSync(
    path.join(root, 'scripts/changelog.js'),
    "\nif (version === '2.18.0') throw Error('Generation failed.');\n",
  );
  const files = [
    'readme.txt',
    `${SLUG}.php`,
    'package.json',
    'package-lock.json',
    'changelog.txt',
  ];
  const original = files.map((file) =>
    fs.readFileSync(path.join(root, file), 'utf8'),
  );
  assert.throws(() => prepareRelease(root, '2.18.0'), /Generation failed/);
  assert.deepEqual(
    files.map((file) => fs.readFileSync(path.join(root, file), 'utf8')),
    original,
  );
});

test('manifest excludes local files and CLI PHP fixtures, and requires readable build sources', (t) => {
  const root = fixture(t);
  put(root, 'local-note.txt', 'Untracked local work.');
  const files = distributionFiles(root);
  assert.equal(files.includes('local-note.txt'), false);
  assert.equal(
    files.some(
      (file) =>
        file.startsWith('tests/') ||
        file === '.env.private' ||
        file === 'scripts/php-lint.php',
    ),
    false,
  );
  assert.ok(files.includes('src/index.js'));
  fs.appendFileSync(path.join(root, '.distignore'), 'src/\n');
  assert.throws(() => distributionFiles(root), /Required distribution file/);
});

test('ZIP verification rejects stale contents, omissions and unsafe or duplicate entries before staging', (t) => {
  const root = fixture(t);
  assert.doesNotThrow(() => validateArchive(root, projectZip(root)));
  assert.doesNotThrow(() =>
    validateArchive(root, projectZip(root, [[`${SLUG}/src/`, '', 0o40755]])),
  );
  assert.throws(
    () => validateArchive(root, projectZip(root, [], ['LICENSE'])),
    /Missing ZIP entries/,
  );
  for (const name of [
    `${SLUG}/../escape.txt`,
    `${SLUG}/readme.txt`,
    'unexpected.txt',
  ]) {
    const destination = path.join(root, 'must-not-exist');
    assert.throws(
      () => validateArchive(root, projectZip(root, [[name, '']]), destination),
      /Unsafe or duplicate|Unexpected ZIP/,
    );
    assert.equal(fs.existsSync(destination), false);
  }
  assert.throws(
    () =>
      validateArchive(root, projectZip(root, [[`${SLUG}/link`, '', 0o120777]])),
    /non-file ZIP/,
  );
  const zip = projectZip(root);
  put(root, 'build/index.js', 'Changed after the ZIP was built.');
  assert.throws(
    () => validateArchive(root, zip),
    /differs from the current checkout/,
  );
});

test('ZIP staging uses verified bytes and refuses an existing directory', (t) => {
  const root = fixture(t);
  const zip = projectZip(root);
  const destination = path.join(root, 'staged');
  validateArchive(root, zip, destination);
  assert.equal(
    fs.readFileSync(path.join(destination, SLUG, 'readme.txt'), 'utf8'),
    fs.readFileSync(path.join(root, 'readme.txt'), 'utf8'),
  );
  assert.throws(() => validateArchive(root, zip, destination), /File exists/);
});

test('ZIP packaging rejects untracked generated assets and omitted runtime stylesheets', (t) => {
  const root = fixture(t);
  git(root, 'rm', '--cached', 'build/index.css');
  assert.throws(() => distributionFiles(root), /build output must be tracked/);
  git(root, 'add', 'build/index.css');
  fs.appendFileSync(path.join(root, '.distignore'), 'build/index.css\n');
  assert.throws(
    () => distributionFiles(root),
    /missing or excluded: build\/index.css/,
  );
});

test('unchanged tracked files produce identical archives', async (t) => {
  const root = fixture(t);
  const hash = (filename) =>
    createHash('sha256').update(fs.readFileSync(filename)).digest('hex');
  const filename = await createArchive(root);
  const first = hash(filename);
  await createArchive(root);
  assert.equal(hash(filename), first);
});
