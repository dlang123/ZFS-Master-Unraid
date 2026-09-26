const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '..');
const manifest = fs.readFileSync(path.join(root, 'zfs.master.plg'), 'utf8');
const version = manifest.match(/<!ENTITY version "([^"]+)"/)[1];
const archive = path.join(root, `zfs.master-${version}.tgz`);
const checksum = crypto.createHash('md5').update(fs.readFileSync(archive)).digest('hex');
assert.equal(checksum, manifest.match(/<MD5>([^<]+)<\/MD5>/)[1]);
assert.doesNotMatch(manifest, /rc\.nginx/, 'Plugin install/remove must not restart Unraid services');
const entries = execFileSync('tar', ['-tf', archive], {encoding: 'utf8'}).trim().split(/\r?\n/);
let checked = 0;
function visit(directory) {
  for (const entry of fs.readdirSync(path.join(root, directory), {withFileTypes: true})) {
    const relative = `${directory}/${entry.name}`;
    if (entry.isDirectory()) { visit(relative); continue; }
    const source = fs.readFileSync(path.join(root, relative));
    assert.ok(entries.includes(relative), `Missing ${relative}`);
    const packaged = execFileSync('tar', ['-xOf', archive, relative], {maxBuffer: 20 * 1024 * 1024});
    assert.ok(source.equals(packaged), `Stale archive file: ${relative}`);
    if (/\.(?:php|page|js|sh|lua)$/.test(relative) || relative.endsWith('/nchan/zfs_master')) {
      assert.equal(source.includes(Buffer.from('\r\n')), false, `Non-Unix line endings: ${relative}`);
    }
    checked++;
  }
}
visit('zfs.master');
assert.equal(entries.filter(entry => !entry.endsWith('/')).length, checked, 'Unexpected archive files');
console.log(`Package ${version}: checksum and all ${checked} source files verified`);
