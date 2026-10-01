import {test} from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp, mkdir, readFile, writeFile, copyFile, rm} from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {buildAssets, verifyAssets} from '../tools/build-assets.mjs';

test('immutable assets rebuild, change URLs, reject stale outputs and preserve old objects', async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), 'mrn-recaptcha-assets-'));
  const source = path.join(root, 'source'), output = path.join(root, 'out');
  const sourceCommit = 'a'.repeat(40);
  try {
    await mkdir(path.join(source, 'assets'), {recursive: true});
    for (const file of ['.node-version', 'package-lock.json', 'assets/comment-protection.js']) await copyFile(new URL('../' + file, import.meta.url), path.join(source, file));
    const first = await buildAssets({source, output, sourceCommit});
    await verifyAssets({source, output, sourceCommit});
    const second = await buildAssets({source, output, sourceCommit});
    assert.deepEqual(first, second, 'clean rebuild is byte deterministic');
    const old = first.assets['mrn-recaptcha-comments'];
    const oldBytes = await readFile(path.join(output, old.minified.path));
    const original = await readFile(path.join(source, 'assets/comment-protection.js'), 'utf8');
    await writeFile(path.join(source, 'assets/comment-protection.js'), original.replace('12000', '13000'));
    await assert.rejects(verifyAssets({source, output, sourceCommit}), /Stale/);
    const next = await buildAssets({source, output, sourceCommit: 'b'.repeat(40)});
    assert.notEqual(next.assets['mrn-recaptcha-comments'].minified.path, old.minified.path, 'source behavior change gets a new URL without a version bump');
    assert.deepEqual(await readFile(path.join(output, old.minified.path)), oldBytes, 'prior object remains byte identical');
    const current = next.assets['mrn-recaptcha-comments'].minified;
    await writeFile(path.join(output, current.path), 'tampered');
    await assert.rejects(verifyAssets({source, output, sourceCommit: 'b'.repeat(40)}), /checksum/);
    await assert.rejects(buildAssets({source, output, sourceCommit: 'b'.repeat(40)}), /collision/);
    await rm(path.join(output, current.path));
    await assert.rejects(verifyAssets({source, output, sourceCommit: 'b'.repeat(40)}), /ENOENT/);
  } finally { await rm(root, {recursive: true, force: true}); }
});
