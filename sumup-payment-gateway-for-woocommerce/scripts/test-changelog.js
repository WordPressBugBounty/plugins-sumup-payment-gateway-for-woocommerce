const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { test } = require('node:test');
const { renderChangelog, updateChangelog } = require('./changelog');

const readme = `=== Example Gateway ===
Stable tag: 2.0.0

== Changelog ==
= 2.0.0 - 2026-11-01 =
* Fixed: Payment confirmation.

= 1.0 =
* Initial release.

== Upgrade Notice ==
= 2.0.0 =
Upgrade instructions are not release notes.
`;

test('renders Woo changelog format from known dates and excludes other sections', () => {
  const expected = '*** Example Gateway Changelog ***\n\n2026-11-01 - version 2.0.0\n\n* Fixed - Payment confirmation.\n';
  assert.equal(renderChangelog(readme), expected);
  assert.equal(renderChangelog(readme.replace(/\n/g, '\r\n')), expected);
});

test('retains dated release history and leaves unreleased notes out', () => {
  const updated = readme.replace('== Changelog ==', '== Changelog ==\n= Unreleased =\n* New: Next feature.');
  assert.equal(renderChangelog(updated), renderChangelog(readme));
  const datedHistory = readme.replace('= 1.0 =', '= 1.0 - 2025-01-01 =');
  assert.match(renderChangelog(datedHistory), /2025-01-01 - version 1\.0\n\n\* Initial release\./);
});

test('rejects missing current dates, invalid dates, duplicate versions and empty notes', () => {
  assert.throws(() => renderChangelog(readme.replace(' - 2026-11-01', '')), /latest release needs/);
  assert.throws(() => renderChangelog(readme.replace('2026-11-01', '2026-02-30')), /Invalid publication date/);
  assert.throws(() => renderChangelog(readme.replace('= 1.0 =', '= 2.0.0 =')), /Duplicate changelog version/);
  assert.throws(() => renderChangelog(readme.replace('* Fixed: Payment confirmation.', '')), /Missing changelog notes/);
});

test('check detects missing and stale output without overwriting it', (context) => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'sumup-changelog-'));
  context.after(() => fs.rmSync(root, { recursive: true, force: true }));
  fs.writeFileSync(path.join(root, 'readme.txt'), readme);
  assert.throws(() => updateChangelog(root, true), /changelog.txt is stale/);
  updateChangelog(root);
  updateChangelog(root, true);
  fs.writeFileSync(path.join(root, 'changelog.txt'), 'old release notes');
  assert.throws(() => updateChangelog(root, true), /changelog.txt is stale/);
  assert.equal(fs.readFileSync(path.join(root, 'changelog.txt'), 'utf8'), 'old release notes');
});
