#!/usr/bin/env node
/**
 * Fetch the pinned @qrauth/web-components release from the npm registry,
 * verify its tarball integrity, extract the IIFE build, and copy it into
 * assets/js/ so WordPress can enqueue a vendored copy.
 *
 * The pinned version and expected sha512 live in package.json under the
 * "qrauth" key — bump both together when upgrading.
 *
 * Zero npm dependencies: uses fetch, node:crypto, and the system `tar`.
 */

import { createHash } from 'node:crypto';
import { execFile } from 'node:child_process';
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { createWriteStream } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { pipeline } from 'node:stream/promises';
import { tmpdir } from 'node:os';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);
const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..');

const PACKAGE_NAME = '@qrauth/web-components';
const IIFE_FILENAME = 'qrauth-components.js';
const TARBALL_IIFE_PATH = `package/dist/${IIFE_FILENAME}`;
const DEST_DIR = resolve(ROOT, 'assets/js');
const DEST_PATH = resolve(DEST_DIR, IIFE_FILENAME);

async function readPackageMeta() {
	const pkg = JSON.parse(await readFile(resolve(ROOT, 'package.json'), 'utf8'));
	const meta = pkg.qrauth ?? {};
	if (!meta.webComponentsVersion) {
		throw new Error('package.json "qrauth.webComponentsVersion" is not set.');
	}
	if (!meta.webComponentsIntegrity?.startsWith('sha512-')) {
		throw new Error('package.json "qrauth.webComponentsIntegrity" must be an sha512 SRI string.');
	}
	return meta;
}

async function fetchRegistryManifest(version) {
	const url = `https://registry.npmjs.org/${PACKAGE_NAME}/${version}`;
	const res = await fetch(url);
	if (!res.ok) {
		throw new Error(`Registry request failed: ${res.status} ${res.statusText} (${url})`);
	}
	return res.json();
}

function verifyIntegrity(buffer, expectedIntegrity) {
	const expected = expectedIntegrity.replace(/^sha512-/, '');
	const actual = createHash('sha512').update(buffer).digest('base64');
	if (actual !== expected) {
		throw new Error(
			`Tarball integrity mismatch.\n  expected: sha512-${expected}\n  actual:   sha512-${actual}`
		);
	}
}

async function downloadTarball(url, destination) {
	const res = await fetch(url);
	if (!res.ok || !res.body) {
		throw new Error(`Failed to download tarball: ${res.status} ${res.statusText}`);
	}
	await pipeline(res.body, createWriteStream(destination));
}

async function extractIIFE(tarballPath, workDir) {
	await execFileAsync('tar', ['-xzf', tarballPath, '-C', workDir, TARBALL_IIFE_PATH]);
	const extracted = resolve(workDir, TARBALL_IIFE_PATH);
	const contents = await readFile(extracted);
	if (contents.length === 0) {
		throw new Error(`Extracted IIFE at ${TARBALL_IIFE_PATH} was empty.`);
	}
	return contents;
}

async function main() {
	const { webComponentsVersion, webComponentsIntegrity } = await readPackageMeta();
	console.log(`› Fetching ${PACKAGE_NAME}@${webComponentsVersion}`);

	const manifest = await fetchRegistryManifest(webComponentsVersion);
	const tarballUrl = manifest?.dist?.tarball;
	const registryIntegrity = manifest?.dist?.integrity;
	if (!tarballUrl) {
		throw new Error('Registry manifest did not include a tarball URL.');
	}
	if (registryIntegrity && registryIntegrity !== webComponentsIntegrity) {
		throw new Error(
			`Registry integrity does not match pin.\n  registry: ${registryIntegrity}\n  pinned:   ${webComponentsIntegrity}`
		);
	}

	const workDir = await mkdtemp(resolve(tmpdir(), 'qrauth-wc-'));
	const tarballPath = resolve(workDir, 'package.tgz');
	try {
		await downloadTarball(tarballUrl, tarballPath);
		const tarballBuffer = await readFile(tarballPath);
		verifyIntegrity(tarballBuffer, webComponentsIntegrity);

		const iifeBuffer = await extractIIFE(tarballPath, workDir);

		await mkdir(DEST_DIR, { recursive: true });
		const header = Buffer.from(
			[
				'/*!',
				` * ${PACKAGE_NAME} v${webComponentsVersion}`,
				' * Vendored by qrauth-passwordless-social-login. Do not edit by hand.',
				' *',
				' * Source:  https://github.com/qrauth-io/qrauth/tree/main/packages/web-components',
				' * License: MIT',
				' * npm:     https://www.npmjs.com/package/@qrauth/web-components',
				' * Build:   `npm install && npm run build:assets` (see bin/fetch-web-components.mjs)',
				' */',
				'',
			].join('\n')
		);
		await writeFile(DEST_PATH, Buffer.concat([header, iifeBuffer]));
		console.log(`✓ Wrote ${DEST_PATH} (${iifeBuffer.length} bytes)`);
	} finally {
		await rm(workDir, { recursive: true, force: true });
	}
}

main().catch((err) => {
	console.error(`✗ ${err.message}`);
	process.exit(1);
});
