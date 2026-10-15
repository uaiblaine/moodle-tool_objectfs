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

Files the fork added and upstream does not have: `FORK.md`, `docs/` (audit reports), `CLAUDE.md`, `CHANGELOG.md`, `COPYING.txt`,
`phpcs.xml`, `.phpcsignore`, `.moodle-plugin-ci.yml`, `.gitattributes`,
`classes/local/object_manipulator/candidates/candidates_cursor.php` and its test.

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
