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

### Fixed

- The deleter no longer unlinks the local copy unless the external object has the same size as the local file
  and the recorded size (a wrong object at the key could destroy the only good copy).
- The orphan grace period (`maxorphanedage`) counts from the moment of orphaning, not from the last
  duplication; the upgrade stamps existing orphaned rows with the upgrade time. The metadata-only removal no
  longer deletes a row whose content hash still has a `{files}` row.
- `reconcile_filedir` skips files of unknown age and never removes a file younger than one day.
- The S3 range-request proxy verifies TLS again, and its failure message no longer includes the signed query string.
- The tag-migration task status badge renders on Bootstrap 5.
- With external deletion set to full, orphans past the grace period also lose their remote object (upstream
  #693, ported ahead of its merge).
- `mutations/gates.conf`: mutation spec holding each fork guard to the test written for it.

### Added

- `FORK.md` (branch model, sync procedure, patch ledger), `CLAUDE.md`, `COPYING.txt`, `phpcs.xml`.

## 2026081202 (MOODLE_501_STABLE, before this changelog)

- Moodle 5.1/5.2 AWS SDK fixes (#740, #685), keyset pagination for the checker and orphaner candidate
  scans, composite index on `tool_objectfs_objects`, status check registration fix, upstream merges up to
  #762.
