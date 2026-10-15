# Changelog

All notable changes to this fork are documented in this file. Upstream's own changes are in
[catalyst/moodle-tool_objectfs](https://github.com/catalyst/moodle-tool_objectfs/commits/MOODLE_404_STABLE);
the fork patches and their upstream status are in `FORK.md`.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

### Changed

- Moodle 5.3 only: `requires` 5.3 (`2026100500`), `supported = [503, 503]`, PHP 8.3+. The 5.1/5.2 line is
  frozen on `MOODLE_501_STABLE` (tag `archive/501-final`).
- Merged upstream `MOODLE_404_STABLE` up to e2ac620 (release `2026041012`): connection status check next to
  the store settings, SDK credential provider chain check, `preferexternal` moved to the general settings,
  presigning refused for long-lived public cache headers (#469), pre-signed URL normalisation in editor
  content (#368).
- Release names follow `<upstream release>+uai.<N>`; `$plugin->version` follows upstream's date scheme.
- CI: one MAH job for `MOODLE_503_STABLE`.

### Added

- `FORK.md` (branch model, sync procedure, patch ledger), `CLAUDE.md`, `COPYING.txt`, `phpcs.xml`.

## 2026081202 (MOODLE_501_STABLE, before this changelog)

- Moodle 5.1/5.2 AWS SDK fixes (#740, #685), keyset pagination for the checker and orphaner candidate
  scans, composite index on `tool_objectfs_objects`, status check registration fix, upstream merges up to
  #762.
