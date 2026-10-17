# Releasing qrauth-passwordless-social-login

This plugin is published to the WordPress.org plugin directory at
https://wordpress.org/plugins/qrauth-passwordless-social-login/.

## Release flow (automated)

Every git tag matching `v*.*.*` triggers `.github/workflows/wp-deploy.yml`,
which builds the plugin and publishes to WordPress.org SVN via
[10up/action-wordpress-plugin-deploy](https://github.com/10up/action-wordpress-plugin-deploy).

### To release a new version

1. Bump three version markers consistently:
   - `qrauth-passwordless-social-login.php` — `Version:` header AND `QRAUTH_PSL_VERSION` constant
   - `readme.txt` — `Stable tag:` line
   - `package.json` — `version` field

2. Update `readme.txt`:
   - Prepend new `= X.Y.Z =` blocks to `== Changelog ==` and `== Upgrade Notice ==`

3. Commit + open PR + merge to `main`.

4. Tag the release from `main`:
```bash
   git checkout main && git pull
   git tag vX.Y.Z
   git push --tags
```

5. Watch the workflow run:
```bash
   gh run watch
```

6. Verify the published version:
```bash
   open "https://wordpress.org/plugins/qrauth-passwordless-social-login/"
```

   Plugin page should show the new version within ~15 minutes.

## Updating plugin directory assets

Banner, icon, and screenshot files live in `.wordpress-org/` in this repo.
Any commit to that directory is deployed on the next tag push (NOT immediately
on merge). To force an asset-only deploy without a code change, manually
update the SVN `/assets/` directory via `svn ci` against
https://plugins.svn.wordpress.org/qrauth-passwordless-social-login/assets/.

Required filenames are documented in `.wordpress-org/README.md`.

## Initial bootstrap

The initial v0.1.21 publish was done manually via `svn ci` on
2026-06-01; the GH Actions deploy takes over from v0.1.22 onwards.
See git history for context.
