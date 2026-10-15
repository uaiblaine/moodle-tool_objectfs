# Claude instructions for `tool_objectfs`

This file is auto-loaded as context whenever Claude works in this plugin's directory tree.
**Fleet-wide standards live in `~/dev/CLAUDE.md`** — not repeated here. This file keeps what is
true for this plugin, as rules with a one-line reason each; keep it under ~250 lines.

Plugin context: an admin **tool** plugin ("Object storage file system") and a **fork** of
`catalyst/moodle-tool_objectfs`. It replaces Moodle's file system (`$CFG->alternative_file_system_class`)
with one that pushes file content to an object store (S3 and S3-compatible stores such as Google Cloud
Storage through its XML API, Azure Blob, DigitalOcean Spaces, OpenStack Swift) and can redirect
downloads to pre-signed URLs (S3 or CloudFront). It owns `tool_objectfs_objects` (one row per content
hash and its location), `tool_objectfs_reports`, `tool_objectfs_report_data`, and the tagging tables.
Supports Moodle **5.3 only** (`$plugin->requires = 2026100500`, `$plugin->supported = [503, 503]`),
hence PHP 8.3+. CI is the moodle-an-hochschulen reusable workflow, one job per supported branch in
`.github/workflows/ci.yml` — **update those jobs when `supported` changes**.

**Read `FORK.md` before touching any file upstream also owns**: branch roles, the sync procedure and
the patch ledger live there, and a change to an upstream file without a ledger row is lost at the
next sync.

## Agent orchestration budget (fleet rule, repeated here on purpose)

Section 6 of `~/dev/CLAUDE.md` (`moodle-dev/CLAUDE.fleet.md`) is the authority and says why.
This short copy reaches sessions that do not load that file: cloud sessions and checkouts
outside `~/dev`. Every subagent gets the model and effort of its role from its agent
definition, and none runs on the session model.

| Role | model | effort | agent |
|---|---|---|---|
| Mechanical sweeps, greps, renames, counts, log reading | `haiku` | `medium` | `fleet-sweeper` |
| Checklists against evidence (handoff counts, spec lines against a sweep log, lang lockstep) | `haiku` | `high` | `fleet-checker` |
| Readers, measurers, graders | `sonnet` | `medium` | `fleet-reader` |
| Refuters and verifiers of a blocking finding | `sonnet` | `high` | `fleet-verifier` |
| Well-scoped implementation (established cause, settled design, written recipe) | `sonnet` | `medium` | `fleet-fixer` |
| Non-trivial implementation (open design, several files, long tasks) | `opus` | `high` | `fleet-implementer` |
| Consolidators, critics, estimators, ADR and documentation drafters | `opus` | `high` | `fleet-synthesist` |

- Launch the `Agent` tool with `subagent_type: "fleet-*"`; it has no `effort` parameter, so
  the role's effort comes from that definition (`mdl claude-setup` installs them). Where they
  are not installed, pass `model`. Aliases only; never `fable`; `xhigh` only for a long-horizon
  implementer whose prompt says why; never `xhigh`/`max` on Sonnet or Haiku.
- No long command inside a subagent: `mdl ci --matrix`, `mdl mutate` and Behat run from the
  main session in a background Bash command; a subagent runs the fast gate its prompt names and
  reports the command with its counts.
- Workflows only on the user's opt-in, every `agent()` with `agentType: 'fleet-*'`, under 10
  agents. Advisor off by default.

## Commands

```sh
mdl ci moodle-tool_objectfs-503 --matrix --behat   # pre-merge gate (repo dir name, not a path)
mdl ci moodle-tool_objectfs-503 --only phpunit     # one leg, fast iteration
mdl phpunit m503 tool_objectfs                     # once the checkout is mounted on m503
```

The 5.3 checkout is `~/dev/moodle-tool_objectfs-503` (a worktree of `~/dev/moodle-tool_objectfs`,
branch `main`). Until the mount switch, `plugins.conf` still mounts the old checkout
(`MOODLE_501_STABLE`, frozen) on m501/m502; `mdl ci` runs the 5.3 checkout by directory name without
any mount.

## Code layout

```
classes/local/manager.php                 config, client factory, location lookups
classes/local/store/object_file_system.php  the \file_system subclass core calls; local vs external reads
classes/local/store/<store>/client.php    one client per store; define_client_section() adds its settings
classes/local/store/signed_url.php        pre-signed URL value object; CloudFront lives in the S3 client
classes/local/object_manipulator/         pusher/puller/deleter/checker/orphaner/recoverer + candidates
classes/local/tag/                        object tagging sources and sync
classes/task/                             scheduled and adhoc tasks driving the manipulators
classes/check/                            \core\check checks (connection, credentials, tagging, proxy range)
FORK.md                                   branch model, sync procedure, patch ledger
```

## Architecture gotchas

- **`tool_objectfs_objects.location` is a single small int today; upstream is replacing it with three
  bit columns** (`in_filedir`, `in_mdl_files`, `in_remote`) in two upgrade steps (#771 `2026092200`,
  `bits-and-bobs` `2026092300`). Never write a new query or index on `location` in `main` without a
  `FORK.md` row: it will have to be ported, and an index on `location` makes phase 2 fail.
- **Never install `sync/bits-preview` on a site that has data you keep** — see `FORK.md`.
- The `\core_files\hook\before_editor_content_saved` callback in `db/hooks.php` (upstream #368) targets a
  hook class core 5.3 does not have; it is dormant until core ships it, so the pre-signed URL
  normalisation in editor content does not run.
- The AWS SDK is core's (`public/lib/aws-sdk`); `local_aws` must not be installed beside it (a second
  SDK shadows core's). `ensure_sdk_loaded()` in the S3 client exists because `Aws\manifest()` is a
  namespaced function PHP cannot autoload.
- Version numbers follow upstream's date scheme, not the fleet's `20261005XX` namespace: `FORK.md`,
  "Sync procedure", says why.

## Testing notes

- Store clients are exercised against the `test_file_system` / `unavailable_test_file_system` doubles
  in `classes/tests/`; real-store integration tests need credentials in `config.php`
  (`$CFG->phpunit_objectfs_*`, see `TESTING.md`) and are skipped without them.

## When in doubt

Follow the patterns in existing files, and the upstream's patterns in upstream files.
