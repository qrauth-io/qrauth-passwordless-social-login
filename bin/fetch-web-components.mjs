#!/usr/bin/env node
/**
 * Fetch the pinned @qrauth/web-components release from the npm registry,
 * verify its tarball integrity, extract the IIFE build, and copy it into
 * assets/js/ so WordPress can enqueue a vendored copy.
 *
 * Also vendors the unminified TypeScript source for the same release into
 * assets/js/source/ so WordPress.org plugin reviewers (and integrators) can
 * read human-readable code without leaving the plugin tarball. Source files
 * are pulled from a pinned commit SHA on the public qrauth-io/qrauth
 * monorepo and verified against the git Tree-API blob hashes.
 *
 * The pinned npm version + sha512 and the source-commit SHA live in
 * package.json under the "qrauth" key — bump them together when upgrading.
 *
 * Zero npm dependencies: uses fetch, node:crypto, and the system `tar`.
 */

import { createHash } from 'node:crypto';
import { execFile } from 'node:child_process';
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { createWriteStream } from 'node:fs';
import { dirname, posix as posixPath, resolve } from 'node:path';
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
const SOURCE_DEST_DIR = resolve(DEST_DIR, 'source');
const ALLOWED_SOURCE_EXTENSIONS = new Set(['.ts', '.js', '.mjs', '.json']);

async function readPackageMeta() {
	const pkg = JSON.parse(await readFile(resolve(ROOT, 'package.json'), 'utf8'));
	const meta = pkg.qrauth ?? {};
	if (!meta.webComponentsVersion) {
		throw new Error('package.json "qrauth.webComponentsVersion" is not set.');
	}
	if (!meta.webComponentsIntegrity?.startsWith('sha512-')) {
		throw new Error('package.json "qrauth.webComponentsIntegrity" must be an sha512 SRI string.');
	}
	if (!/^[0-9a-f]{40}$/.test(meta.webComponentsSourceCommit ?? '')) {
		throw new Error('package.json "qrauth.webComponentsSourceCommit" must be a full 40-char git SHA.');
	}
	if (!/^[\w.-]+\/[\w.-]+$/.test(meta.webComponentsSourceRepo ?? '')) {
		throw new Error('package.json "qrauth.webComponentsSourceRepo" must be "<owner>/<repo>".');
	}
	if (!meta.webComponentsSourcePath) {
		throw new Error('package.json "qrauth.webComponentsSourcePath" is not set.');
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

/**
 * Compute git's blob hash for a file's raw bytes.
 * git blob SHA = sha1("blob " + size + "\0" + content)
 */
function gitBlobSha1(buffer) {
	const header = Buffer.from(`blob ${buffer.length}\0`);
	return createHash('sha1').update(Buffer.concat([header, buffer])).digest('hex');
}

async function fetchSourceTree({ webComponentsSourceRepo, webComponentsSourceCommit, webComponentsSourcePath }) {
	const url = `https://api.github.com/repos/${webComponentsSourceRepo}/git/trees/${webComponentsSourceCommit}?recursive=1`;
	const res = await fetch(url, { headers: { Accept: 'application/vnd.github+json' } });
	if (!res.ok) {
		throw new Error(`GitHub Tree API request failed: ${res.status} ${res.statusText} (${url})`);
	}
	const tree = await res.json();
	if (tree.truncated) {
		throw new Error(
			`GitHub Tree API returned truncated tree for ${webComponentsSourceRepo}@${webComponentsSourceCommit}; cannot vendor source reliably.`
		);
	}
	const prefix = webComponentsSourcePath.replace(/\/$/, '') + '/';
	const blobs = tree.tree.filter(
		(entry) => entry.type === 'blob' && entry.path.startsWith(prefix)
	);
	if (blobs.length === 0) {
		throw new Error(
			`No source blobs found under ${prefix} at ${webComponentsSourceCommit}.`
		);
	}
	for (const blob of blobs) {
		const ext = posixPath.extname(blob.path);
		if (!ALLOWED_SOURCE_EXTENSIONS.has(ext)) {
			throw new Error(
				`Refusing to vendor unexpected file type '${ext}' at ${blob.path}. Update ALLOWED_SOURCE_EXTENSIONS if intentional.`
			);
		}
	}
	return blobs.map((blob) => ({
		relativePath: blob.path.slice(prefix.length),
		fullPath: blob.path,
		expectedSha: blob.sha,
		size: blob.size,
	}));
}

async function fetchAndVerifyBlob({ entry, webComponentsSourceRepo, webComponentsSourceCommit }) {
	const url = `https://raw.githubusercontent.com/${webComponentsSourceRepo}/${webComponentsSourceCommit}/${entry.fullPath}`;
	const res = await fetch(url);
	if (!res.ok) {
		throw new Error(`Failed to download ${entry.fullPath}: ${res.status} ${res.statusText}`);
	}
	const buffer = Buffer.from(await res.arrayBuffer());
	const actualSha = gitBlobSha1(buffer);
	if (actualSha !== entry.expectedSha) {
		throw new Error(
			`Blob hash mismatch for ${entry.fullPath}.\n  expected: ${entry.expectedSha}\n  actual:   ${actualSha}`
		);
	}
	return buffer;
}

function vendorBannerFor({ relativePath, version, repo, commit, sourcePath }) {
	const upstream = `${sourcePath.replace(/\/$/, '')}/${relativePath}`;
	return Buffer.from(
		[
			'/*!',
			` * ${PACKAGE_NAME} v${version} — ${upstream}`,
			' * Vendored unminified source. Do not edit by hand.',
			' *',
			` * Upstream: https://github.com/${repo}/blob/${commit}/${upstream}`,
			' * License:  MIT',
			` * npm:      https://www.npmjs.com/package/${PACKAGE_NAME}`,
			' *',
			' * Present for compliance with WordPress.org plugin guideline 4',
			' * (human-readable source for compiled assets). Not loaded at runtime —',
			` * only the IIFE bundle at assets/js/${IIFE_FILENAME} is enqueued.`,
			' */',
			'',
		].join('\n')
	);
}

function readmeFor({ version, repo, commit, sourcePath, files }) {
	const upstreamTreeUrl = `https://github.com/${repo}/tree/${commit}/${sourcePath}`;
	const lines = [
		'# Web Components — vendored source',
		'',
		`The TypeScript files in this directory are an unminified copy of the source for the \`${PACKAGE_NAME}\` package whose compiled IIFE bundle is shipped at \`assets/js/${IIFE_FILENAME}\`.`,
		'',
		`These files are vendored from [${repo}/${sourcePath}](${upstreamTreeUrl}) at commit \`${commit}\` (the \`${PACKAGE_NAME}\` v${version} release on npm). They are present here for compliance with WordPress.org plugin guideline 4 (human-readable code). They are **not loaded or executed by the plugin at runtime** — only the compiled bundle at \`assets/js/${IIFE_FILENAME}\` is enqueued.`,
		'',
		'## Files',
		'',
		...files.map((f) => `- \`${f.relativePath}\` — git blob \`${f.expectedSha}\` (${f.size} bytes)`),
		'',
		'## Provenance',
		'',
		`- npm release: https://www.npmjs.com/package/${PACKAGE_NAME}/v/${version}`,
		`- npm tarball sha512: see \`package.json#qrauth.webComponentsIntegrity\``,
		`- Source repository: https://github.com/${repo}`,
		`- Source commit: https://github.com/${repo}/commit/${commit}`,
		`- Source path: \`${sourcePath}\``,
		'',
		'Each file in this directory was downloaded from `raw.githubusercontent.com` at the pinned commit and verified against the git blob hash returned by GitHub\'s Tree API before being written. The compiled IIFE next to this directory was downloaded from the npm registry and verified against the sha512 SRI hash pinned in `package.json`.',
		'',
		'## Rebuilding the compiled bundle from these sources',
		'',
		'1. Clone the upstream repository: `git clone https://github.com/' + repo + '.git`',
		'2. `cd ' + repo.split('/')[1] + '/' + posixPath.dirname(sourcePath) + '`',
		'3. `npm install`',
		'4. `npm run build`',
		'',
		`Output appears at \`dist/${IIFE_FILENAME}\`.`,
		'',
		'## Updating',
		'',
		'This directory is regenerated by `npm run build:assets` (see `bin/fetch-web-components.mjs`). Bump `qrauth.webComponentsVersion`, `qrauth.webComponentsIntegrity`, and `qrauth.webComponentsSourceCommit` in `package.json` together when upgrading.',
		'',
		`License: MIT (the bundled \`${PACKAGE_NAME}\` package; see ../${IIFE_FILENAME} banner for full attribution).`,
		'',
	];
	return Buffer.from(lines.join('\n'));
}

async function vendorSource(meta) {
	const { webComponentsVersion, webComponentsSourceRepo, webComponentsSourceCommit, webComponentsSourcePath } = meta;
	console.log(`› Vendoring source from ${webComponentsSourceRepo}@${webComponentsSourceCommit.slice(0, 12)}:${webComponentsSourcePath}`);

	const entries = await fetchSourceTree(meta);

	await rm(SOURCE_DEST_DIR, { recursive: true, force: true });
	await mkdir(SOURCE_DEST_DIR, { recursive: true });

	for (const entry of entries) {
		const buffer = await fetchAndVerifyBlob({
			entry,
			webComponentsSourceRepo,
			webComponentsSourceCommit,
		});
		const banner = vendorBannerFor({
			relativePath: entry.relativePath,
			version: webComponentsVersion,
			repo: webComponentsSourceRepo,
			commit: webComponentsSourceCommit,
			sourcePath: webComponentsSourcePath,
		});
		const dest = resolve(SOURCE_DEST_DIR, entry.relativePath);
		await mkdir(dirname(dest), { recursive: true });
		await writeFile(dest, Buffer.concat([banner, buffer]));
		console.log(`  ✓ ${entry.relativePath} (${buffer.length} bytes, blob ${entry.expectedSha.slice(0, 12)})`);
	}

	const readme = readmeFor({
		version: webComponentsVersion,
		repo: webComponentsSourceRepo,
		commit: webComponentsSourceCommit,
		sourcePath: webComponentsSourcePath,
		files: entries,
	});
	await writeFile(resolve(SOURCE_DEST_DIR, 'README.md'), readme);
	console.log(`  ✓ README.md`);
}

async function main() {
	const meta = await readPackageMeta();
	const { webComponentsVersion, webComponentsIntegrity } = meta;
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
				' *',
				' * The unminified TypeScript source for this bundle is also vendored at',
				' * assets/js/source/ — see assets/js/source/README.md for provenance.',
				' */',
				'',
			].join('\n')
		);
		await writeFile(DEST_PATH, Buffer.concat([header, iifeBuffer]));
		console.log(`✓ Wrote ${DEST_PATH} (${iifeBuffer.length} bytes)`);

		await vendorSource(meta);
	} finally {
		await rm(workDir, { recursive: true, force: true });
	}
}

main().catch((err) => {
	console.error(`✗ ${err.message}`);
	process.exit(1);
});
