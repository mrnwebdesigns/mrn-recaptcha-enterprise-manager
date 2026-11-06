import test from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {mkdtemp, mkdir, copyFile, rm} from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {buildAssets} from '../tools/build-assets.mjs';

const source = fileURLToPath(new URL('../', import.meta.url));

test('real adapter admits each qualified version and retains native loading for unqualified versions', async () => {
  const fixture = await mkdtemp(path.join(os.tmpdir(), 'mrn-recaptcha-adapter-matrix-'));
  try {
    await mkdir(path.join(fixture, 'tests'));
    await mkdir(path.join(fixture, 'includes'));
    for (const name of ['tests/adapter.php', 'includes/class-mrn-recaptcha-form-loader.php']) {
      await copyFile(path.join(source, name), path.join(fixture, name));
    }
    // Synthetic identity is confined to this disposable build-contract fixture.
    await buildAssets({source, output: fixture, sourceCommit: '4'.repeat(40)});
    for (const version of ['2.0.1.1', '2.0.2.1', '2.0.2.2', '2.0.4']) {
      const output = execFileSync('php', [path.join(fixture, 'tests/adapter.php'), version], {encoding: 'utf8'});
      assert.match(output, /PASS qualified form enables adapter/, version);
      assert.match(output, /PASS qualified adapter config/, version);
      assert.match(output, /PASS immutable local URL/, version);
      assert.match(output, /PASS recovery dependency retained/, version);
    }
    for (const version of ['2.0.2', '2.0.2.3', '2.0.3', '2.0.4.1', '2.0.5', '2.1.0']) {
      const output = execFileSync('php', [path.join(fixture, 'tests/adapter.php'), version], {encoding: 'utf8'});
      assert.match(output, /PASS unqualified vendor version unchanged/, version);
      assert.doesNotMatch(output, /PASS qualified form enables adapter/, version);
    }
  } finally {
    await rm(fixture, {recursive: true, force: true});
  }
});
