const fs = require('node:fs');
const { ZipArchive } = require('archiver');
const path = require('node:path');
const {
  checkProject,
  distributionFiles,
  validateArchive,
} = require('./scripts/release');

async function createArchive(root = __dirname) {
  const { name, version } = checkProject(root);
  const filename = path.join(root, `${name}-${version}.zip`);
  const temporary = `${filename}.partial`;
  const files = distributionFiles(root);
  const archive = new ZipArchive({ zlib: { level: 9 } });
  const output = fs.createWriteStream(temporary);

  try {
    await new Promise((resolve, reject) => {
      output.on('close', resolve);
      output.on('error', reject);
      archive.on('error', reject);
      archive.on('warning', reject);
      archive.pipe(output);
      for (const file of files) {
        archive.append(fs.readFileSync(path.join(root, file)), {
          name: `${name}/${file}`,
          date: new Date('1980-01-01T00:00:00Z'),
          mode: 0o644,
        });
      }
      archive.finalize().catch(reject);
    });
    validateArchive(root, temporary);
    fs.renameSync(temporary, filename);
    console.log(`Created and validated ${filename}`);
    return filename;
  } catch (error) {
    archive.abort();
    output.destroy();
    fs.rmSync(temporary, { force: true });
    throw error;
  }
}

if (require.main === module) {
  createArchive().catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
  });
}
module.exports = { createArchive };
