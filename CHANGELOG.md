# Changelog

All notable changes to this fork are documented in this file. Upstream's own changes are in
[catalyst/moodle-tool_objectfs](https://github.com/catalyst/moodle-tool_objectfs/commits/MOODLE_404_STABLE);
the fork patches and their upstream status are in `FORK.md`.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

### Added

- Google Cloud Storage store (`\tool_objectfs\gcs_file_system`): the Cloud Storage XML API through core's AWS
  SDK, with path-style addressing, no canned ACL (works with uniform bucket-level access), no SDK flexible
  checksums, tagging reported unsupported, objects verified by the CRC32C/MD5 digests Cloud Storage reports
  before a local copy is deleted, and range requests read on the authenticated client instead of through a
  pre-signed URL. A bucket used through the S3 store can switch without moving objects. Design and later
  stages (keyless authentication, Cloud CDN, load-balancer check) in `docs/gcp/DESIGN.md`.
- Keyless authentication for the Cloud Storage store:
  - **Attached service account:** the service account of the VM, GKE workload or Cloud Run service, through the
    metadata server; no key is stored.
  - **Service-account key file:** read from disk.
  - In both modes the requests carry an OAuth bearer token, and signed URLs are V4 URLs signed by the service
    account: through IAM `signBlob`, cached, or locally with the key file.
  - Non-ASCII download names are sent in the RFC 6266 form.
  - A site that already has an HMAC key keeps using it until another mode is chosen.
- Cloud CDN for the Cloud Storage store:
  - **Signing method "Cloud CDN for media":** inline audio, image and video files (the list is configurable)
    redirect to Cloud CDN signed URLs (Expires, KeyName, HMAC-SHA1 Signature), one per object.
  - **Downloads stay on Cloud Storage URLs,** because a backend bucket leaves the download name out of its cache key.
  - **File names through the CDN** are signed into CDN URLs only when switched on.
  - **Cache-Control:** an optional value applied to every new object.
  - The signing-method select shows the store's own choices.
- Status checks for the Cloud Storage store, listed under Reports > System status when that store is selected:
  - **Bucket probe:** writes an object without an ACL and checks the digest Cloud Storage reports for it.
  - **Client address:** behind the load balancer, checks that Moodle records the address the load balancer saw:
    X-Forwarded-For read, Client-IP ignored, the load balancer in reverseproxyignore, and no client-supplied entry
    used.
  - **docs/gcp/infrastructure.md:** builds the bucket, the CDN and a dev stack, and lists the checks to run against
    them.

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
