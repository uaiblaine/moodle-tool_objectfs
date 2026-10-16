# Fork ledger: uaiblaine/moodle-tool_objectfs

This repository is a fork of [catalyst/moodle-tool_objectfs](https://github.com/catalyst/moodle-tool_objectfs).
It supports **Moodle 5.3 and later only** (PHP 8.3+, the floor of Moodle 5.3 itself) and carries
improvements that are not, or not yet, upstream. This file is the contract that lets every upstream
release be merged without losing them: each fork change is listed with the files it touches, why it
exists and where it stands upstream. Read it before every sync and update it in the same pull request
as any change to a file that upstream owns.

## Branches

| Branch | Role |
|---|---|
| `main` = `MOODLE_503_STABLE` | Fork development line, Moodle 5.3. When Moodle 5.4 ships, `MOODLE_504_STABLE` branches from `main`, `main` follows it, and `MOODLE_503_STABLE` stays as the 5.3 maintenance line. |
| `MOODLE_404_STABLE` | **Mirror** of `catalyst/MOODLE_404_STABLE` (upstream's single branch for Moodle 4.4 to 5.x). Fast-forward only, never a fork commit. It exists so the merge base of every sync is visible on GitHub. |
| `sync/upstream-YYYY-MM-DD` | One per sync; becomes the pull request into `main`. |
| `sync/bits-preview` | Upstream's unreleased location-bits refactor (#771 then `MOODLE_404_STABLE-bits-and-bobs`) with the fork patches ported onto it. **Never merged into `main` and never installed on a production site** until upstream merges it into `MOODLE_404_STABLE`: it changes the schema, and a site that runs an unreleased upgrade step can never follow upstream's released one. |
| `MOODLE_501_STABLE` | Frozen 5.1/5.2 line, tag `archive/501-final`. No further development. |

## Sync procedure

```sh
git -C ~/dev/moodle-tool_objectfs-503 fetch upstream
git -C ~/dev/moodle-tool_objectfs-503 push origin upstream/MOODLE_404_STABLE:MOODLE_404_STABLE   # mirror, fast-forward
git -C ~/dev/moodle-tool_objectfs-503 switch -c sync/upstream-$(date +%F) main
git -C ~/dev/moodle-tool_objectfs-503 merge --no-ff upstream/MOODLE_404_STABLE
```

Pushing the `MOODLE_404_STABLE` mirror fires upstream's `ci.yml` on GitHub; its catalyst workflow skips every
job (measured 9 s), which is acceptable.

1. Always **merge, never rebase or squash** upstream: the merge keeps upstream's commits as ancestors,
   so the next sync conflicts only on what is new. A cherry-picked or squashed upstream commit comes
   back as a conflict on every later sync.
2. Resolve each conflict against the patch table below. A patch that upstream has superseded is
   removed from the table in the same merge, with the upstream commit that replaced it.
3. Conflicts that always resolve the same way:
   - `version.php`: keep the fork's file, then set `$plugin->version` to today's `YYYYMMDD00` (or the
     next counter) and `$plugin->release` to `'<upstream version merged>+uai.<N>'`. Upstream dates its
     upgrade savepoints by commit date, so the fork's version must be a date at or after the merge:
     a version in Moodle 5.3's `20261005XX` namespace (the fleet rule for branch-per-version plugins)
     would sit below the first upstream savepoint dated after 5 October 2026, and an upgrade step above
     `$plugin->version` breaks every upgrade. `requires` and `supported` stay the fork's.
   - `.github/workflows/ci.yml`: keep the fork's (MAH reusable workflow, one job per supported branch);
     upstream's calls catalyst-moodle-workflows.
   - `db/install.xml` `VERSION` attribute: the higher of the two.
4. Run `mdl ci moodle-tool_objectfs-503 --matrix --behat` on the merge result before the pull request.

## Fork patches

Status: **fork** = fork only for now, offered upstream once proven in production; **pending** =
upstream has an open pull request covering it; **dropped** = superseded upstream, kept here one sync for
reference.

| # | Patch | Files upstream also owns | Status |
|---|---|---|---|
| P1 | Moodle 5.x AWS SDK fixes (#740, #685): `new S3Client()` instead of the removed `S3Client::factory()`, `ensure_sdk_loaded()` requires `lib/aws-sdk/src/functions.php` so `Aws\manifest()` exists in CLI upgrades, `UnresolvedAuthSchemeException` caught in `test_connection()`, `get_exception_details()` no longer hides every error. | `classes/local/store/s3/client.php`, `classes/local/store/digitalocean/client.php`, `classes/local/store/azure_blob_storage/client.php`, `tests/local/store/clients_test.php` | fork (upstream PR #691 covers part of the auth-scheme catch) |
| P2 | Keyset pagination for the checker and orphaner candidate scans, with a cursor persisted in config (`candidates_cursor`), committed only after the batch outcome is known (`commit_cursor($processedcount)`, `manipulator::execute()` returns how many records it reached). | `classes/local/object_manipulator/candidates/*`, `classes/local/object_manipulator/{checker,manipulator,manipulator_builder}.php`, their tests | fork. **Upstream deletes these classes in `bits-and-bobs`**: the port onto `bitmask_candidates` lives in `sync/bits-preview`. |
| P3 | Composite index `toolobjeobje_locfiltim_ix` (`location`, `filesize`, `timeduplicated`) on `tool_objectfs_objects`, upgrade step `2026061601`. | `db/install.xml`, `db/upgrade.php`, `tests/db_test.php` | fork. **Blocks upstream's phase-2 step** (`2026092300` drops `location`; the DDL refuses to drop a field an index uses): the preview drops this index first. |
| P4 | `tool_objectfs_status_checks()` lost the tagging checks through an array union over numeric keys. | `lib.php`, `tests/lib_test.php` | fork |
| P5 | `manipulator_builder::execute()` checks client availability before fetching and guards an unconfigured file system (a fatal on five task paths). | `classes/local/object_manipulator/manipulator_builder.php`, `classes/tests/unavailable_test_file_system.php` | fork |
| P6 | Moodle 5.3 only: `requires 2026100500`, `supported [503, 503]`; MAH CI. | `version.php`, `.github/workflows/ci.yml` | fork by design |
| P7 | README: warning against `local_aws` on Moodle 4.4+, fork notice. | `README.md` | fork |
| P8 | Lang strings re-sorted: upstream #469 added `settings:presignedurl:presignpublicurls*` out of order, which fails the zero-warning phpcs gate. | `lang/en/tool_objectfs.php` | fork (trivial; offer upstream) |
| P9 | Deleter size guard (audit A2): `delete_object_from_local_by_hash()` unlinks the local copy only when the external object's size (stream-wrapper `filesize()`) equals the local size and the recorded size; a mismatch or unknown size keeps the location DUPLICATED. The old test that deleted after a different-content copy now asserts the opposite. | `classes/local/store/object_file_system.php`, `tests/object_file_system_test.php` | fork (offer upstream) |
| P10 | Orphan grace counts from the orphaning (audit A3): `manager::upsert_object()` restamps `timeduplicated` on the first transition to ORPHANED, so for ORPHANED rows `timeduplicated` means "time orphaned" (the column is overloaded, no schema change); data-only upgrade step `2026101501` stamps existing ORPHANED rows with the upgrade time; the metadata-only branch of `delete_orphaned_object_metadata` gets the `{files}` anti-join the trash branch has. | `classes/local/manager.php`, `classes/task/delete_orphaned_object_metadata.php`, `db/upgrade.php`, `version.php` | fork (offer upstream) |
| P11 | `reconcile_filedir` fails closed (audit A4): a file whose `filemtime()` is false is skipped and the minimum age is floored at one day even when `minorphanedage` is lower or 0. | `classes/task/reconcile_filedir.php`, `tests/task/task_reconcile_filedir_test.php` | fork (offer upstream) |
| P12 | S3 `proxy_range_request` (audit A5): TLS verification is no longer disabled for the pre-signed URL fetch, and the failure exception carries scheme, host and path only (no signature or credential). | `classes/local/store/s3/client.php`, `tests/object_file_system_test.php` | fork (offer upstream) |
| P13 | Tag-migration task status badge uses Bootstrap 5 classes with a stated text colour (audit A7). | `classes/task/update_object_tags.php`, `tests/task/update_object_tags_test.php` | fork (offer upstream) |
| P14 | Upstream #693 ported (open upstream PR; same logic, re-wrapped for the PSR-12 multi-line `if` the zero-warning phpcs gate requires, so a sync conflict on these lines resolves to either side): with `deleteexternal` = full, `delete_orphaned_object_metadata` also removes the remote object of an orphan past the grace period instead of leaving it in the bucket. Safe only because P10 measures the grace from the orphaning. | `classes/task/delete_orphaned_object_metadata.php`, `tests/task/delete_orphaned_object_metadata_external_test.php` | pending (#693); drop the row when upstream merges it |
| P15 | `phpunit.xml`: `cacheResult="false"` (PHPUnit wrote `.phpunit.result.cache` into the plugin directory, which `mdl mutate --fast` reads as the checkout changing); `version.php` spells the component in single quotes (`mdl mutate` parses only that form). | `phpunit.xml`, `version.php` | fork by design |
| P16 | Google Cloud Storage store (`docs/gcp/DESIGN.md`): `\tool_objectfs\gcs_file_system` added to `manager::get_available_fs_list()`, one array line; the store itself is new files under `classes/local/store/gcs/`. Its client subclasses the S3 client, so **on every sync check that the members it uses still exist with the same meaning**: `$client`, `$bucket`, `$bucketkeyprefix`, `$config`, `$maxupload`, `$expirationtime`, `$presignedminfilesize`, `$enablepresignedurls`, `get_filepath_from_hash()`, `ensure_sdk_loaded()`, `is_functional()`, `get_exception_details()`, `get_proxy_string()`, `upload_to_s3()`, `set_client()`, `generate_presigned_url()`, `proxy_range_request()`, `test_range_request()`, `get_exception_details()`, `get_expiration_time()`, `MAX_TEMP_LIMIT`; and `$client` stays the variable that holds the client in `settings.php` (P18). Its tests fail when one is renamed. | `classes/local/manager.php` | fork (offer upstream as one new store once proven) |
| P17 | Fork lang block: the GCS store's strings, keyed `settings:gcs:*`, sit together between `settings:fulldelete` and `settings:generalheader`, so a sync conflicts only if upstream changes one of those two neighbours. | `lang/en/tool_objectfs.php` | fork by design |
| P18 | The signing-method select offers the store client's own methods when the client has `get_signing_methods()` (the GCS store: Cloud Storage or Cloud CDN), upstream's S3/CloudFront otherwise; four lines around the select. | `settings.php` | fork |

Files the fork added and upstream does not have: `FORK.md`, `docs/` (audit reports), `tests/task/delete_orphaned_object_metadata_test.php`, `tests/task/delete_orphaned_object_metadata_external_test.php`, `mutations/` (mutation spec, `mdl mutate`), `CLAUDE.md`, `CHANGELOG.md`, `COPYING.txt`,
`phpcs.xml`, `.phpcsignore`, `.moodle-plugin-ci.yml`, `.gitattributes`,
`classes/local/object_manipulator/candidates/candidates_cursor.php` and its test, the GCS store
(`classes/gcs_file_system.php`, `classes/local/store/gcs/`, `classes/tests/test_gcs_integration_client.php`,
`tests/local/store/gcs/`).

## Rules that keep the fork mergeable

- **New behaviour goes into new files.** Google Cloud Storage, Cloud CDN and Cloud Armor work lands as
  new classes (a store under `classes/local/store/<name>/`, a signed-URL provider, a check) plugged into
  the existing extension points (`manager::get_available_fs_list()`, the client `define_client_section()`
  settings, `\core\check`). An edit to an upstream file is the last resort and gets a row above.
- **PHP 8.3 modernisation applies only to fork-owned files.** Rewriting upstream files for syntax alone
  turns every later upstream edit to them into a conflict. Defects in upstream files are fixed with the
  smallest diff and recorded here.
- **Generic fixes are offered upstream** after they have run in production and been reviewed: every patch
  catalyst accepts is one row less here.
