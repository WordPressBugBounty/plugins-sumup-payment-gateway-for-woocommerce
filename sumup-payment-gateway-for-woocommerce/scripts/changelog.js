const fs = require('node:fs');
const path = require('node:path');

// readme.txt is authoritative. Only export releases with known publication dates;
// older, undated release notes remain available in the readme.
function renderChangelog(readme) {
  const normalized = readme.replace(/\r\n/g, '\n');
  const title = normalized.match(/^=== (.+) ===$/m)?.[1];
  const section = normalized.match(/^== Changelog ==\s*\n([\s\S]*?)(?=^== [^=]+ ==\s*$|$(?![\s\S]))/m)?.[1];
  if (!title || !section) {
    throw new Error('readme.txt must contain a plugin title and Changelog section.');
  }

  const entries = [...section.matchAll(/^= (.+) =\s*$/gm)];
  const versions = new Set();
  const releases = [];
  let latest = true;
  for (let index = 0; index < entries.length; index++) {
    const entry = entries[index];
    if (entry[1] === 'Unreleased') continue;
    const match = entry[1].match(/^(\d+(?:\.\d+)+)(?: - (\d{4}-\d{2}-\d{2}))?$/);
    if (!match) throw new Error(`Invalid changelog heading: ${entry[1]}`);
    const [, version, date] = match;
    if (versions.has(version)) throw new Error(`Duplicate changelog version: ${version}`);
    versions.add(version);
    if (latest && !date) throw new Error('The latest release needs a YYYY-MM-DD publication date.');
    latest = false;
    if (!date) continue;
    if (Number.isNaN(Date.parse(date)) || new Date(date).toISOString().slice(0, 10) !== date) {
      throw new Error(`Invalid publication date: ${date}`);
    }
    const notes = section.slice(entry.index + entry[0].length, entries[index + 1]?.index).trim();
    if (!notes) throw new Error(`Missing changelog notes for ${version}`);
    const formatted = notes.replace(/^\* (Fixed|Added|New|Changed|Improved|Improvements?|Removed):\s*/gm, (_, type) => {
      const label = { New: 'Added', Improved: 'Changed', Improvement: 'Changed', Improvements: 'Changed' }[type] || type;
      return `* ${label} - `;
    });
    releases.push(`${date} - version ${version}\n\n${formatted}`);
  }
  if (!releases.length) throw new Error('No dated releases found in readme.txt.');
  return `*** ${title} Changelog ***\n\n${releases.join('\n\n')}\n`;
}

function updateChangelog(root, check = false) {
  const output = renderChangelog(fs.readFileSync(path.join(root, 'readme.txt'), 'utf8'));
  const destination = path.join(root, 'changelog.txt');
  if (check) {
    if (!fs.existsSync(destination) || fs.readFileSync(destination, 'utf8') !== output) {
      throw new Error('changelog.txt is stale. Run node scripts/changelog.js and commit the result.');
    }
  } else {
    fs.writeFileSync(destination, output);
  }
}

if (require.main === module) {
  try {
    if (process.argv.slice(2).some((argument) => argument !== '--check')) {
      throw new Error('Usage: node scripts/changelog.js [--check]');
    }
    updateChangelog(path.resolve(__dirname, '..'), process.argv.includes('--check'));
  } catch (error) {
    console.error(error.message);
    process.exitCode = 1;
  }
}

module.exports = { renderChangelog, updateChangelog };
