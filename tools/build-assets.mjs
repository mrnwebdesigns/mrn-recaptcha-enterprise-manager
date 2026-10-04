#!/usr/bin/env node
/** Build one immutable source/minified asset pair from an exported source commit. */
import {createHash} from 'node:crypto';
import {readFile, writeFile, mkdir} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {transform, version as esbuildVersion} from 'esbuild';

const component = 'mrn-recaptcha-enterprise-manager';
const inputs = [
  {handle: 'mrn-recaptcha-form-loader', name: 'wpforms-form-loader', dependencies: ['jquery'], external: ['https://www.google.com/recaptcha/api.js']},
  {handle: 'mrn-recaptcha-comments', name: 'comment-protection', dependencies: [], external: ['https://www.google.com/recaptcha/enterprise.js']},
];
const digest = bytes => createHash('sha256').update(bytes).digest('hex');

export async function buildAssets({source, output, sourceCommit}) {
  if (!/^[a-f0-9]{40}$/.test(sourceCommit)) throw new Error('An immutable source commit is required');
  const nodeVersion = (await readFile(path.join(source, '.node-version'), 'utf8')).trim();
  if (process.versions.node !== nodeVersion || esbuildVersion !== '0.25.10') throw new Error('Locked asset toolchain mismatch');
  const assets = {};
  await mkdir(path.join(output, 'assets/generated'), {recursive: true});
  for (const input of inputs) {
    const sourceBytes = await readFile(path.join(source, `assets/${input.name}.js`));
    const result = await transform(sourceBytes.toString('utf8'), {
      loader: 'js', minify: true, charset: 'utf8', legalComments: 'inline',
      sourcefile: `assets/${input.name}.js`,
    });
    const minifiedBytes = Buffer.from(result.code);
    await mkdir(path.join(output, 'assets/generated'), {recursive: true});
    const emit = async (bytes, suffix) => {
      const sha256 = digest(bytes);
      const file = `assets/generated/${input.name}.${sha256}${suffix}`;
      try {
        await writeFile(path.join(output, file), bytes, {flag: 'wx'});
      } catch (error) {
        if (error.code !== 'EEXIST' || digest(await readFile(path.join(output, file))) !== sha256) throw new Error('Immutable asset collision: ' + file);
      }
      return {path: file, sha256, bytes: bytes.length};
    };
    assets[input.handle] = {
      source: await emit(sourceBytes, '.js'), minified: await emit(minifiedBytes, '.min.js'),
      dependencies: input.dependencies, external_dependencies: input.external,
    };
  }
  const manifest = {
    schema: 1, component, source_commit: sourceCommit,
    build: {node: nodeVersion, esbuild: esbuildVersion, lock_sha256: digest(await readFile(path.join(source, 'package-lock.json')))},
    assets,
  };
  await writeFile(path.join(output, 'assets/manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
  return manifest;
}

export async function verifyAssets({source, output, sourceCommit}) {
  const manifest = JSON.parse(await readFile(path.join(output, 'assets/manifest.json'), 'utf8'));
  if (manifest.source_commit !== sourceCommit || manifest.component !== component || manifest.schema !== 1) throw new Error('Asset source identity mismatch');
  const nodeVersion = (await readFile(path.join(source, '.node-version'), 'utf8')).trim();
  if (manifest.build.node !== nodeVersion || process.versions.node !== nodeVersion || manifest.build.esbuild !== esbuildVersion || esbuildVersion !== '0.25.10') throw new Error('Locked asset toolchain mismatch');
  if (JSON.stringify(Object.keys(manifest.assets).sort()) !== JSON.stringify(inputs.map(input => input.handle).sort())) throw new Error('Asset set mismatch');
  for (const input of inputs) {
    const asset = manifest.assets[input.handle];
    if (JSON.stringify(asset.dependencies) !== JSON.stringify(input.dependencies) || JSON.stringify(asset.external_dependencies) !== JSON.stringify(input.external)) throw new Error('Asset dependency graph mismatch');
    const sourceBytes = await readFile(path.join(source, `assets/${input.name}.js`));
    const minified = Buffer.from((await transform(sourceBytes.toString('utf8'), {loader: 'js', minify: true, charset: 'utf8', legalComments: 'inline', sourcefile: `assets/${input.name}.js`})).code);
    const expected = {source: sourceBytes, minified};
    for (const [variant, bytes] of Object.entries(expected)) {
      const entry = manifest.assets[input.handle][variant];
      const suffix = variant === 'minified' ? '.min.js' : '.js';
      const expectedPath = `assets/generated/${input.name}.${digest(bytes)}${suffix}`;
      if (entry.path !== expectedPath || entry.sha256 !== digest(bytes) || entry.bytes !== bytes.length) throw new Error('Stale asset variant or manifest: ' + variant);
      if (digest(await readFile(path.join(output, entry.path))) !== entry.sha256) throw new Error('Emitted asset checksum mismatch');
    }
  }
  if (manifest.build.lock_sha256 !== digest(await readFile(path.join(source, 'package-lock.json')))) throw new Error('Asset dependency lock mismatch');
  return manifest;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  const [source, output, sourceCommit] = process.argv.slice(2);
  if (!source || !output || !sourceCommit) throw new Error('Usage: node tools/build-assets.mjs SOURCE OUTPUT COMMIT');
  await buildAssets({source, output, sourceCommit});
  await verifyAssets({source, output, sourceCommit});
}
