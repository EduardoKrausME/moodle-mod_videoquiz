# Changelog

This changelog documents only the two most recent published GitHub releases. Each entry describes verified changes between its release tag and the immediately preceding published release. Changes made later on `master` are excluded.

## 1.0.7 - 2026-10-15

### Changed

- Updated the GitHub Actions CI PostgreSQL service from version 16 to 17.
- Updated the MariaDB CI service from version 10 to 11.4, including revised startup environment variables and health-check configuration.
- Added the plugin icon image at `pix/icon.png` and reorganized the SVG logo asset as `pix/monologo.svg`.
- Updated the plugin release identifier from `1.0.6` to `1.0.7` and its build number from `2026101200` to `2026101500`.

No changes to Video Quiz activity functionality were identified in this tag comparison.

[Compare 1.0.6...1.0.7](https://github.com/EduardoKrausME/moodle-mod_videoquiz/compare/1.0.6...1.0.7)

## 1.0.6 - 2026-10-12

### Fixed

- Explicitly loaded `backup_videoquiz_stepslib.php` and `restore_videoquiz_stepslib.php` from their respective backup and restore activity task classes, addressing missing structure-step class loading.
- Added `defined('MOODLE_INTERNAL') || die();` guards to both backup and restore task classes.
- Hardened the release workflow against shell injection by reading the head commit message from the `RELEASE_MESSAGE` environment variable rather than directly interpolating it in a shell script.

### Changed

- Updated the PHP 8.3 CI matrix entry from Moodle 5.2 (`MOODLE_502_STABLE`) to Moodle 5.3 (`MOODLE_503_STABLE`); the Moodle 4.5 entry was retained.
- Removed the `Build clean Moodle package` step from the release workflow, along with its verification of the root `videoquiz/` directory and the `videoquiz-<version>.zip` file attachment in GitHub Release creation. As a result, the workflow no longer creates or attaches that installable ZIP package automatically.
- Updated the plugin release identifier from `1.0.5` to `1.0.6` and its build number from `2026100500` to `2026101200`.

[Compare 1.0.5...1.0.6](https://github.com/EduardoKrausME/moodle-mod_videoquiz/compare/1.0.5...1.0.6)
