# Google Cloud integration: design for owner approval

Status: **approved 2026-10-15** (O1-O4 decided; section 8). G1-G3 merged (#2, #3); G4 implemented on
`feat/gcs-checks` (gates P16_*/P18_*/P19_* in `mutations/gates.conf`; section 9 records what implementation changed in
this design). Real-bucket and real-CDN items wait for a GCP test project; `docs/gcp/infrastructure.md` builds one.

Read `FORK.md` first. Every choice below follows its rule: new behaviour goes in **new files** plugged into the
existing extension points, and each unavoidable edit to an upstream-owned file gets a ledger row.

Evidence markers used throughout:

- **[C file:line]**: read in this repository or in core 5.3 (`~/dev/moodle-503/public`).
- **[D n]**: checked against Google's current documentation on 2026-10-15. `n` is the row in
  "Appendix A: documentation checks".
- **[U]**: not confirmed by any document. Each one has a real-bucket test in the test plan before anything
  depends on it.

## 1. What exists today

- GCS works only as the S3 store pointed at `https://storage.googleapis.com` with HMAC keys (audit,
  "Input for the GCS phase" 1). It runs through the XML API's S3 interoperability layer [D1].
- The S3 client always sends a canned ACL. `ObjectUploader` gets `$this->bucketacl` for both simple and
  multipart uploads [C s3/client.php:570-600, lib/aws-sdk/src/S3/ObjectUploader.php:77-89]. Under uniform
  bucket-level access, ACL operations fail with 400 [D4]. The settings also offer AWS-only values such as
  `aws-exec-read` [C s3/client.php:481-489].
- SDK 3.369.22 defaults to `request_checksum_calculation` = `response_checksum_validation` = `when_supported`
  [C lib/aws-sdk/src/S3/S3Client.php:270-300]. Google documents neither the `x-amz-checksum-*` headers nor
  trailing checksums. It documents only MD5 and CRC32C through `x-goog-hash` / `Content-MD5`, and
  `aws-chunked` with extra constraints [D5]. **[U]** whether the defaults work against GCS.
- Tagging is reported as supported (audit A10) [C s3/client.php:1031]. Google documents no S3 tagging [D3, U].
- The signed-URL provider is `s3 | cf` [C s3/client.php:620-626, settings.php:307-315]. The S3 and CloudFront
  signers and `get_nice_filename()` are `private` [C s3/client.php:634, 673, 727], and so are
  `$signingmethod` and `$bucketacl`.
- `proxy_range_request()` always presigns with the S3 signer and fetches over `\curl` [C s3/client.php:839-899].
  Ranges are capped at 5 MiB per response [C object_file_system.php `get_valid_http_ranges`].
- Credentials are either HMAC or the AWS provider chain. There is no GCE / GKE / Cloud Run path.
- Uploads set no `Cache-Control` or `Content-Disposition`.
- The plugin does nothing about the client IP. Core decides it, and its rules matter behind a Google load
  balancer (section 4).

### The extension points (all in upstream files)

| Point | Where | What a new store needs |
|---|---|---|
| Store list | `manager::get_available_fs_list()` [C manager.php:330-350], a hard-coded array | **One line**, unavoidable: the `filesystem` select validates against these choices |
| Class mapping | `manager::get_client_classname_from_fs()` maps `\tool_objectfs\X_file_system` to `\tool_objectfs\local\store\X\client` | Nothing: name the new files to fit it |
| Store settings | `$client->define_client_section($settings, $config)` [C settings.php:219-222], called before the pre-signed URL block | Nothing: the new client adds its own settings |
| Signing select | `settings.php:307-315`, choices `['s3' => 'S3', 'cf' => 'CloudFront']` | Nothing if the new store carries its own signing setting (2 lines optional, E2) |
| Status checks | `tool_objectfs_status_checks()` [C lib.php:111] | One line per new check (E3), or zero via `admin_setting_check` inside the store section |
| Config defaults | `manager::get_objectfs_config()` sets a default per key [C manager.php:60-120] | Nothing: the new client reads its keys with `?? default` |
| Caches | `db/caches.php` | Nothing: `cache::make_from_params()` creates an ad-hoc application cache [C core cache/classes/cache.php:207] |
| Lang strings | `lang/en/tool_objectfs.php` | **Unavoidable**: one file per component. Keep the fork's strings in one contiguous block (E4) |

## 2. Google Cloud Storage

### 2.1 The options compared

**(a) Harden the S3-interop path.** Keep the AWS SDK data path: the `s3://` stream wrapper, `ObjectUploader`
and multipart. Set the checksum options to `when_required`, send no canned ACL, report tagging unsupported, use
path-style addressing, and set region `auto`. Credentials stay HMAC: HMAC keys work on the XML API only, are
limited to 10 per service account [D28], and are long-lived secrets in `config_plugins`. Google recommends an
attached service account or ADC for most production workloads rather than keys [D28]. Signing stays the SDK's
SigV4, which GCS accepts on its XML endpoint [D1, D11].

**(b) A native GCS store.** JSON/XML API through `\curl`, OAuth2 tokens from the metadata server or a key file,
V4 signed URLs from `signBlob` or a local key, and resumable uploads [D7-D12]. No composer dependency. The cost
is not merge conflicts, since it is all new files. The cost is code volume. `object_file_system` reaches the
store through **stream paths**: `is_readable`, `filesize` (the P9 size guard), seekable `fopen`, `readfile`,
`copy`, `unlink` and `rename` [C object_file_system.php:253-310, 446-470, 635-690]. So a native store needs its
own `gs://` stream wrapper. The Swift wrapper is 875 lines [C swift/stream_wrapper.php]. Add a JSON client with
retry and backoff, resumable uploads with recovery from a 308 [D7], token providers and a V4 signer, and the
estimate is about 3,000 fork-owned lines that re-implement what the SDK already does and has tested.

**(a+) Recommended: a `gcs` store that subclasses the S3 client and adds GCP-native authentication and
signing.** It follows the DigitalOcean store's pattern [C digitalocean/client.php: `class client extends
s3_client`]:

- **Data path.** The AWS SDK against the XML API, hardened as in (a).
- **Credentials**, in one of three modes:
  - `hmac` (today's behaviour);
  - `metadata`: an OAuth2 token from the metadata server on GCE, GKE and Cloud Run [D9];
  - `keyfile`: a service-account JWT exchanged at `oauth2.googleapis.com/token` [D10].

  In the two OAuth modes the SDK client is built with `'credentials' => false`, which makes it use the
  anonymous signer [C lib/aws-sdk/src/ClientResolver.php:669-674]. A handler-list middleware then adds
  `Authorization: Bearer <token>`. The XML API accepts OAuth bearer tokens: Google's samples use them for PUT,
  multipart initiate and list-objects [D26]. No document lists the supported operations one by one, so the
  whole data path gets a real-bucket test [U].
- **Signing.** A native V4 signer (`GOOG4-RSA-SHA256` through `signBlob` or the key file, or
  `GOOG4-HMAC-SHA256`) [D11, D12]. It is needed because the SDK presigner cannot sign with a bearer token.
- **Integrity.** `verify_object()` compares the object's `x-goog-hash` CRC32C, and its MD5 when present, with
  the local file [D5, D8]. P9 compares sizes only; this compares content.

What (a+) gets from (b): keyless production authentication (no long-lived secret in the database), signed URLs
from the service account, real content verification, and least privilege (bucket-scoped `objectAdmin`; no
`ListBuckets`). What it leaves out: resumable uploads, which the SDK's multipart upload replaces [D2], and the
JSON API, which nothing in the plugin needs.

**Fallback.** If the real-bucket test shows that bearer-token XML requests fail somewhere on the data path (most
likely multipart), the data path moves to (b) and the store's settings, signer and token providers stay.
Section 6 isolates this risk in stage G2.

### 2.2 Cost in fork divergence

| | (a) hardened, in the S3 store | (a+) `gcs` subclass (recommended) | (b) native |
|---|---|---|---|
| Edits to upstream files | `s3/client.php` in 5 places (options, ACL, tagging, path style, settings), `manager.php` defaults, lang | E1 (1 line), E4 (lang block); E2/E3 optional | E1, E4; E2/E3 optional |
| New fork-owned code | ~0 | ~1,200 lines plus tests | ~3,000 lines plus tests |
| Merge risk on upstream sync | High: `s3/client.php` is upstream's busiest file | Low: one array line and one lang block | Low |
| Hidden coupling | none | The subclass depends on the S3 client's **protected and public** surface: `$client`, `$bucket`, `$bucketkeyprefix`, `get_filepath_from_hash()`, `upload_to_s3()`, `set_client()`, `__wakeup()`. If upstream renames one of them, this breaks quietly. The guard is the subclass's tests, plus a sync-checklist step in `FORK.md` | none (only the abstract file system) |
| Keyless authentication | no | yes | yes |
| Offerable upstream | yes, piecemeal | yes, as one new store | yes, but large |

Upstream PR #739 (path style for the S3 and DigitalOcean stores) is **not needed** for this phase. The `gcs`
store sets `use_path_style_endpoint` in its own `set_client()` [C lib/aws-sdk/src/S3/S3Client.php:336].
Path style is the safe default for GCS because virtual-hosted style cannot address bucket names that contain
dots [D6]. Porting #739 for the S3 store is a separate decision; it would edit three upstream files.

### 2.3 Proposed classes (all new files)

```
classes/gcs_file_system.php                    \tool_objectfs\gcs_file_system extends local\store\gcs\file_system (name the mapper expects)
classes/local/store/gcs/file_system.php        extends s3\file_system; initialise_external_client() returns gcs\client
classes/local/store/gcs/client.php             extends s3\client (see below); bearer middleware, Content-Disposition normalisation
classes/local/store/gcs/auth/token_provider.php            interface: get_token(), get_email()
classes/local/store/gcs/auth/cached_token_provider.php     instance + shared application cache, refresh 300 s before expiry
classes/local/store/gcs/auth/metadata_token_provider.php   GCE/GKE/Cloud Run metadata server
classes/local/store/gcs/auth/keyfile_token_provider.php    service-account JWT (RS256) to oauth2.googleapis.com/token; also the blob signer
classes/local/store/gcs/signing/v4_signer.php          GOOG4-RSA-SHA256 canonical request (Google's conformance vectors)
classes/local/store/gcs/signing/blob_signer.php        interface: sign(string): string, get_email()
classes/local/store/gcs/signing/iam_blob_signer.php    iamcredentials signBlob
classes/local/store/gcs/signing/cdn_signer.php         Cloud CDN HMAC-SHA1 (section 3, G3)
classes/check/gcs_bucket.php                   connection + UBLA + hash verification probe (G4)
classes/check/gcp_load_balancer.php            section 4 (G4)
classes/tests/test_gcs_integration_client.php  real-bucket client (run-scoped key prefix)
```

`gcs\client` overrides only public and protected members:

| Member | Behaviour |
|---|---|
| `__construct()` | Reads the `gcs_*` keys with `??` defaults. Sets `$bucket`, `$bucketkeyprefix` (reuses the generic `key_prefix`), `$expirationtime`, `$presignedminfilesize` and `$enablepresignedurls` (the DigitalOcean constructor forgets the last three). Calls `set_client()`. |
| `is_configured()` | Bucket set, and the auth mode's inputs present. |
| `set_client()` | `new S3Client` with `endpoint` = `https://storage.googleapis.com`, `use_path_style_endpoint` = true, `region` = `auto`, both checksum options = `when_required`, and credentials from `hmac`, or `false` plus the bearer middleware for `metadata` / `keyfile`. Google's samples use region `auto`, but the docs do not say how the region is used for SigV4 [D6, U]. |
| `upload_to_s3()` | `ObjectUploader` with **no ACL**. Measured in G1: the multipart path drops a null `ACL`, but the single PUT sends it as an empty `x-amz-acl` header (the REST serializer applies null header members), so a `before_upload` callback unsets the parameter. A MockHandler test pins both paths. `CacheControl` comes from `gcs_cachecontrol` when set. Always `ContentType`. |
| `verify_object()` | `headObject`, then the digests in `x-goog-hash` (`@metadata.headers`): every digest reported must match the local file and at least one must be reported, or verification fails closed. MD5 is absent on composite and CMEK objects [D8]. The SDK keeps only the first value of a repeated header [C lib/aws-sdk/src/WrappedHttpHandler.php:141], so if Cloud Storage repeats `x-goog-hash` instead of folding it, only the first digest is compared (real-bucket item 5). `hash('crc32c')` is native PHP and big-endian like Cloud Storage's encoding (pinned by a test). |
| `test_connection()` | `getObject` on a sentinel key: only the error code `NoSuchKey` means connected. A HEAD would answer 404 for a missing bucket and a missing key alike, since a HEAD response has no body to carry the code; `NoSuchBucket`, 403 and signature errors fail. It does not call `ListBuckets`, which needs project-level rights and `x-goog-project-id` under OAuth [D26]. [U] that Cloud Storage returns S3's `NoSuchKey` / `NoSuchBucket` codes on the XML API: real-bucket item 1. |
| `supports_object_tagging()` | `false` (audit A10). |
| `generate_presigned_url()` | Dispatches on `gcs_signingmethod`: `v4` (native signer) or `cdn` (section 3). |
| `proxy_range_request()`, `curl_range_request_to_presigned_url()`, `test_range_request()` | **No presigning.** `getObject` with `Range: bytes=a-b` on the same authenticated client. The body is streamed to the output in 1 MiB reads instead of buffered, and TLS stays verified by the SDK. P12's leak class is gone because there is no signed URL to leak. |
| `define_client_section()` | The `gcs_*` settings below, plus `admin_setting_check` for `gcs_bucket`. |
| `__wakeup()` | Re-runs the constructor's config reads. The parent restores only `$bucket` [C s3/client.php:135-150]. |
| `get_token_expiry_time()` | `-1`: OAuth tokens rotate themselves and HMAC keys do not expire. |

Every outbound request that does not go through the SDK uses `\curl`, which is fleet rule "outbound HTTP through
Moodle's `\curl`". The metadata-server call is the one exception to its defaults. Core's
`curlsecurityblockedhosts` default contains `169.254.169.254` [C admin/settings/security.php:169-178], and
`metadata.google.internal` resolves there. So that one call uses `new \curl(['ignoresecurity' => true, 'proxy'
=> false])` with the URL **hard-coded** in the class: no setting can point it anywhere, so this is not an SSRF
surface. Tokens are memoised per request and cached in an ad-hoc MUC application cache. The key is
`sha1(mode . identity . scope)`, with no `:` in the key; the cache entry expires 300 s before the token does.

### 2.4 Settings (store section; keys new, names `gcs_*`)

| Key | Type | Default | Note |
|---|---|---|---|
| `gcs_bucket` | text | `''` | |
| `key_prefix` | text | `''` | Same key as the S3 store, so moving from S3+GCS changes no object key |
| `gcs_authmode` | select `metadata` / `keyfile` / `hmac` | `metadata`, or `hmac` when an HMAC key is already saved (O5) | The signer follows the mode: `hmac` presigns SigV4 with the key, `keyfile` signs V4 locally, `metadata` signs V4 through `signBlob`. `signBlob` signatures are cached per object and response headers while at least half their validity remains and never past the requested expiry. |
| `gcs_keyfile` | text (path) | `''` | Path to the service-account JSON **outside the database**. Recommended through `$CFG->forced_plugin_settings`. Never a textarea holding the key (the audit A6 lesson). |
| `gcs_hmac_key`, `gcs_hmac_secret` | text / `configpasswordunmask` | `''` | Shown only in `hmac` mode |
| `signingmethod` (upstream key, E2) | select `s3` / `cdn` | `s3` | On this store the choices come from `client::get_signing_methods()`: `s3` (kept as the select's default) means signed by Cloud Storage, `cdn` sends inline media through Cloud CDN. No store-specific key. |
| `gcs_serviceaccount` | text | `''` | Email for `signBlob` in `iam` mode. In `metadata` mode it is read from `.../service-accounts/default/email` when empty [D9: the path shown on the Cloud Run/GKE pages; U on GCE]. |
| `gcs_cachecontrol` | text | `''` | Empty means no header. See section 3.4. |
| `gcs_cdn_*` | | | Section 3 |

Moving a site from "S3 store + base URL `storage.googleapis.com`" to `gcs` needs no data movement. Copy the
bucket and HMAC key into the `gcs_*` settings (or switch to `metadata`), then switch the file system. A
`cli/gcs_from_s3_settings.php` could do the copy; it is optional and part of stage G1.

## 3. Cloud CDN

### 3.1 Decision: signed URLs, per object; no signed cookies; no URL prefixes

- **Signed URL** = the URL plus `Expires`, `KeyName`, `Signature`, in that order and case-sensitive. The
  signature is base64url HMAC-SHA1 over the whole URL up to and including `KeyName`, made with the **raw**
  16-byte key [D14]. Up to 3 keys per backend [D14]. It is local and fast: no network call, unlike `signBlob`.
- **Signed cookies** [D16] are rejected for the plugin. Objects are content-addressed (`l1/l2/sha1`), so any
  usable prefix covers every object in the bucket. A cookie would turn "may fetch this one file for N minutes"
  into "may fetch any file whose hash they learn". Content hashes appear in URLs and logs, so that is a real
  widening. Prefixes are also no help to the areas that are disallowed today (SCORM/H5P relative links): those
  break because objects have hash keys, not because the URLs are signed.
- **URL-prefix signing** [D15] has the same scope problem and nothing to gain while one Moodle file is one
  object. The signer can produce it, but no setting exposes it.

### 3.2 The filename problem (blocking for downloads through the CDN)

For a backend bucket, Cloud CDN's cache key keeps only the query parameters Cloud Storage recognises (for
example `generation`). Arbitrary parameters are dropped from the key, and only an **include list** can add them
back [D17]. The S3 and CloudFront signers name the download through `response-content-disposition`
[C s3/client.php:634-660, 673-690]. If that parameter reaches the bucket but stays out of the cache key, the
first requester's filename is cached and served to everyone who shares the content hash. That leaks one user's
filename to another and gives everyone the wrong name.

Design:

1. The CDN signer adds `response-content-disposition` and `response-content-type` **only** when
   `gcs_cdn_filenames` is on. The settings help says that turning it on **requires** the backend bucket's cache
   key include list to contain both parameters (`--cache-key-query-string-whitelist`), and
   `infrastructure.md` sets that up. With both parameters in the key, each (hash, filename) pair is its own
   cache entry.
2. Whether GCS honours `response-content-disposition` on a request that is not itself signed (the Cloud CDN
   fill service account fetching it) is **[U]**. Google documents these overrides nowhere in the V4 pages
   either [D11]. If the real-bucket test fails, `gcs_cdn_filenames` is removed and the next rule decides.
3. **Routing rule (default):** a response that Moodle sends with `Content-Disposition: attachment` goes to a
   **V4** URL. Inline media (`video/*`, `audio/*`, `image/*`, PDF viewed inline) goes to the **CDN**. That is the
   traffic where caching pays and the filename barely matters. Setting: `gcs_cdn_filetypes`, a filetypes list
   with the default `web_video, web_audio, web_image`.

### 3.3 Dispatch with the fewest upstream edits

- `gcs\client::generate_presigned_url()` overrides the public method [C s3/client.php:620] and chooses between
  `cdn_signer` and `v4_signer` by `gcs_signingmethod` and the routing rule. Upstream's `s3 | cf` code and
  `settings.php` stay untouched.
- **E2 (optional, 2 lines in `settings.php`):** read the signing choices from the client when it offers them
  (`method_exists($client, 'get_signing_methods')`), so the global select is not a dead S3/CloudFront control on
  a GCS site. Without E2 the global select stays visible, and its help string says the GCS store ignores it.
  `manager::cloudfront_pem_exists()` only warns when `signingmethod` = `cf` [C manager.php:231-247].
- `proxy_range_request()` never goes through the CDN. The server reads ranges straight from the bucket on the
  authenticated SDK client (section 2.3). The CDN serves the browser's own Range requests on redirected URLs,
  since Cloud CDN supports ranges up to 100 GiB with a range-capable origin; GCS is one [D19].

CDN settings (store section):

| Key | Type | Note |
|---|---|---|
| `gcs_cdn_baseurl` | text, validated `https://` host | e.g. `https://media.example.org`. A CDN hostname distinct from `wwwroot`. |
| `gcs_cdn_keyname` | text, `[A-Za-z0-9_-]{1,63}` [D14] | |
| `gcs_cdn_key` | `configpasswordunmask` | base64url of the 16-byte key, decoded before signing [D14]. Hashed before any cache key (fleet rule). |
| `gcs_cdn_filenames` | checkbox, default off | Section 3.2 |
| `gcs_cdn_filetypes` | filetypes, default `web_audio,web_image,web_video` | Section 3.2; matched by the file name's extension with core's `filetypes_util`, as upstream's `signingwhitelist` is |

Key rotation (documented in `infrastructure.md`): add key N+1 to the backend bucket, which holds up to 3 keys.
Then switch `gcs_cdn_keyname` and `gcs_cdn_key`, wait for the maximum URL lifetime (`expirationtime`, capped
at one week [C s3/client.php:792-796]), and remove key N.

### 3.4 Cache headers and the private origin

- **Private origin.** The bucket stays private: uniform bucket-level access on, public access prevention
  enforced. Cloud CDN reads it as `service-PROJECT_NUMBER@cloud-cdn-fill.iam.gserviceaccount.com` with
  `roles/storage.objectViewer`. That account exists only after the first signed-URL key is added to the
  backend bucket [D18].
- **Caching** does not depend on object metadata. Content behind a signed URL may be cached whatever the
  origin's `Cache-Control` says, and `--signed-url-cache-max-age` (default 1 h) governs it [D14, D18].
  `FORCE_CACHE_ALL` would override even that [D18] and is not needed.
- **`gcs_cachecontrol`** is therefore about browsers and V4 URLs, not the CDN. Objects are content-addressed
  and immutable, so `private, max-age=31536000, immutable` is safe and is the suggested value. It is not the
  default: an empty value keeps today's behaviour. The redirect response itself keeps upstream's
  `Cache-Control` logic [C object_file_system.php:870-905].
- Existing objects keep their metadata. An optional `cli/gcs_set_cache_control.php` (copy-in-place with
  replaced metadata) is listed in stage G3. Google limits writes to one per second per object name, metadata
  updates included [D: quotas, from the D2 page], so the CLI runs serially per object, which is natural.
- Keep objects free of `Content-Encoding: gzip`. Gzip-encoded objects over 10 MB can break range requests
  through the CDN [D19]. The plugin never sets it; `infrastructure.md` says so.

## 4. Cloud Armor and the load balancer

### 4.1 What belongs in the plugin: one check

**Core's rules, read in core:**

- `getremoteaddr()` takes the **last** `X-Forwarded-For` entry left after removing the addresses in
  `$CFG->reverseproxyignore` [C lib/moodlelib.php:8736-8764]. It uses `X-Forwarded-For` only when
  `getremoteaddrconf` does not skip it, and the default skips it [C lib/moodlelib.php:378-384].
- The global external Application Load Balancer sends `[<supplied>,]<client-ip>,<load-balancer-ip>` [D21].
  Without `reverseproxyignore` = the load balancer's frontend IP(s), Moodle records **the load balancer as
  every user's address**. Login throttling, logs, IP-restricted roles and web-service tokens then all key on one
  IP. The client's own supplied entries come first and are never used, so the configuration resists spoofing
  once it is right.
- `HTTP_CLIENT_IP` must be skipped. The load balancer passes it through, so a client could set it.
- **`$CFG->reverseproxy` must stay off.** The load balancer forwards the `Host` header unchanged, and with
  `reverseproxy` on, core throws `reverseproxyabused` whenever the request's host equals `wwwroot`'s
  [C lib/setuplib.php:752-754]. ("Reverse proxy" in Moodle's sense means a proxy that rewrites `Host`.)
- `$CFG->sslproxy` must be on when the load balancer talks HTTP to the backend while `wwwroot` is `https`, and
  off when the backend service uses HTTPS [C lib/setuplib.php:723-736]. `X-Forwarded-Proto` tells the check
  which case applies [D21].

**`\tool_objectfs\check\gcp_load_balancer`** (new file, status check):

| Condition | Result |
|---|---|
| CLI, or the request carries no `X-Forwarded-For` | `NA` ("evaluate from a browser through the load balancer") |
| `getremoteaddrconf` skips `X-Forwarded-For` | `ERROR`: every user is the load balancer |
| `HTTP_CLIENT_IP` not skipped | `ERROR`: spoofable |
| `getremoteaddr()` equals the last `X-Forwarded-For` entry | `ERROR`: add the load balancer IP to `reverseproxyignore` |
| `getremoteaddr()` differs from the second-to-last entry | `WARNING`: an extra proxy hop |
| `X-Forwarded-Proto: http` with an `https` `wwwroot` | `NA` (the site would not have loaded; included for completeness) |
| `X-Forwarded-Proto: https`, the backend saw `http`, and `sslproxy` off | `ERROR` |
| otherwise | `OK`, with the derived client IP shown |

It does not detect "behind Google" from headers such as `Via`; no document was checked for them. It reasons
only about `X-Forwarded-For` / `X-Forwarded-Proto`, which apply to any load balancer. It is registered through
E3 (one line in `tool_objectfs_status_checks()`) so it appears under Reports > System status. Without E3 it is
shown only on the plugin settings page through `admin_setting_check`.

### 4.2 What belongs in infrastructure (`docs/gcp/infrastructure.md`, gcloud and Terraform snippets)

- **Two security policies**, because the two backends take different types [D22]:
  - The **Moodle backend service** gets a backend policy (`CLOUD_ARMOR`). Preconfigured WAF on CRS 4.22
    (`sqli-v422-stable`, `xss-v422-stable`, `lfi`, `rfi`, `rce`, `php`, `protocolattack`, `scannerdetection`,
    `sessionfixation`) [D23]. Every rule starts in **preview** mode, and an exclusion is promoted only after its
    logs have been read. Rate limiting (`throttle`, then `rate_based_ban`) on `/login/index.php`,
    `/login/token.php` and `/webservice/` [D23]. Moodle needs field exclusions for rich text:
    `add-preconfig-waf-exclusion` with `--request-uri-to-exclude` / `--request-query-param-to-exclude` on
    editor-posting endpoints such as `/lib/ajax/service.php` [D25]. A rule with field exclusions cannot be an
    `allow` [D25]. The body is inspected only up to 64 kB [D23], so large forum posts are only partly
    inspected; record that as accepted risk.
  - The **CDN backend bucket** gets an edge policy (`CLOUD_ARMOR_EDGE`). It runs before the cache and can match
    only `origin.ip`, `request.headers`, `request.method` and `request.path` / `request.query` [D22]. Use it for
    geo/IP allow-deny and method enforcement (GET/HEAD).
- **Signed-URL paths and WAF.** Preconfigured WAF does not run on a backend bucket at all [D23], so CDN signed
  URLs are never WAF-inspected and need no exclusion. On the backend service, `pluginfile.php` with a `Range`
  header (served through `proxy_range_request`) is the path to watch in preview logs. **[U]** whether
  `protocolattack` flags Moodle's range traffic; there is no exclusion until the logs show one.
- Backend buckets do not support custom **request** headers [D24]. Nothing in this design needs them. The
  backend service can add `X-Client-IP: {client_ip_address}` [D24], but the plugin does not depend on it, because
  core reads only `X-Forwarded-For`.
- Firewall: the backend VMs / NEGs accept traffic only from Google's load-balancer source ranges, so that no
  one can reach the backend directly with a forged `X-Forwarded-For`. **[U]** the exact current ranges: read
  them from the load-balancing firewall page when writing `infrastructure.md`.
- Health checks hit a static file, never a Moodle page: a page would put session and database load on
  every probe.

## 5. FORK.md rows this design would add

| # | Patch | Files upstream also owns | Status |
|---|---|---|---|
| P16 | GCS store registered: `\tool_objectfs\gcs_file_system` added to `manager::get_available_fs_list()` (one array line). Everything else in the store is new files under `classes/local/store/gcs/`. On sync, check that the S3 client's surface the subclass uses still exists: `$client`, `$bucket`, `$bucketkeyprefix`, `get_filepath_from_hash()`, `upload_to_s3()`, `set_client()`, `__wakeup()`, `generate_presigned_url()`, `proxy_range_request()`, `curl_range_request_to_presigned_url()`. | `classes/local/manager.php` | fork (offer upstream as a new store once proven) |
| P17 | Fork lang block: every string the GCS, CDN and load-balancer work adds, keyed `settings:gcs:*` (between `settings:fulldelete` and `settings:generalheader`) and `check:gcp_*`, kept contiguous so a sync conflicts only when upstream adds a key in the same alphabetical neighbourhood. | `lang/en/tool_objectfs.php` | fork by design |
| P18 | *(only if E2 is approved)* The signing select reads its choices from the client when it offers `get_signing_methods()`. | `settings.php` | fork |
| P19 | *(only if E3 is approved)* `tool_objectfs_status_checks()` adds `gcp_load_balancer` (and `gcs_bucket` when the GCS store is selected). | `lib.php` | fork |

New fork-owned files (added to the list under the table in `FORK.md`): `classes/gcs_file_system.php`,
`classes/local/store/gcs/**`, `classes/check/gcs_bucket.php`, `classes/check/gcp_load_balancer.php`,
`classes/tests/test_gcs_integration_client.php`, `tests/local/store/gcs/**`, `tests/check/gcp_load_balancer_test.php`,
`cli/gcs_*.php`, `docs/gcp/**`.

`lang/pt_br`: the plugin has none (audit A15). The fleet lockstep rule applies only once one exists. Starting
one for the fork's strings alone is an owner decision (O6).

## 6. Test plan

### 6.1 PHPUnit with doubles (CI, no network)

| Area | Double | What it pins |
|---|---|---|
| `gcs\client::set_client` | `Aws\MockHandler` plus a history middleware capturing requests | endpoint, path style, `region` = `auto`, no `x-amz-checksum-*` / `x-amz-sdk-checksum-algorithm` / `x-amz-trailer` on PutObject and UploadPart; `Authorization: Bearer` present and no `AWS4-HMAC-SHA256` in OAuth modes |
| `upload_to_s3` | same | No `x-amz-acl` on simple **and** multipart (body > 16 MiB is not needed: pass `mup_threshold` low through a protected seam); `Cache-Control` when set; `Content-Type` always |
| `verify_object` | MockHandler HEAD with crafted `x-goog-hash` | match, CRC32C mismatch, MD5 mismatch, MD5 absent (composite), header absent (fails closed) |
| `test_connection` | 404 / 403 / 200 HEAD | the three verdicts |
| tagging | none | `supports_object_tagging()` false; `tag_manager::is_tagging_enabled_and_supported()` false with the GCS store |
| metadata token provider | `\curl::mock_response()` [C lib/filelib.php:3725] | parses `access_token` / `expires_in`, refreshes 300 s early, cache hit within the window, error on non-200, sends `Metadata-Flavor: Google`, the URL is fixed |
| keyfile token provider | key pair from `openssl_pkey_new()` in the test; `\curl::mock_response()` | JWT header and claims (`iss`, `scope`, `aud`, `iat`, `exp` ≤ `iat` + 3600 [D10]); the signature verifies with the public key; `grant_type` |
| V4 signer | fixed clock; key made by the test | canonical request, string to sign and URL byte for byte against five of Google's conformance vectors (`googleapis/conformance-tests`, `storage/v1/v4_signatures.json`, Apache-2.0, attributed in the test); the RSA step verified with the test's own key, so no private key is committed (GitHub secret scanning would flag the vectors' dummy key) |
| CDN signer | fixed key and clock; expected signature computed independently with `openssl dgst -sha1 -mac HMAC` over the same bytes (and, if available, cross-checked once with `gcloud compute sign-url` **[U]**) | parameter order, base64url, raw-key decoding, `Expires` rounding, existing query parameters kept before `Expires` |
| routing rule (3.2) | headers arrays | attachment goes to V4; inline video goes to CDN; `gcs_cdn_filenames` off means no `response-content-*` in CDN URLs |
| range proxy | MockHandler GetObject with `Range` | the request carries the range; the output is streamed; no signed URL is generated (the signer double fails the test if called) |
| `gcp_load_balancer` check | `$_SERVER` and `$CFG` combinations | every row of the table in 4.1 |
| Privacy | none | nothing new is stored per user; `component_is_compliant()` is unchanged (audit A9 stays open separately) |

Each guard gets a mutation gate in `mutations/gates.conf` (`mdl mutate --fast`, both database families, swept
before the commit that adds the gates). Candidates: ACL omitted, checksum `when_required`, hash mismatch fails
closed, token refresh margin, CDN `filenames` off, attachment-to-V4 routing, the check's
`reverseproxyignore` verdict.

### 6.2 What needs a real bucket (manual or gated by `$CFG->phpunit_objectfs_gcs_integration_test_credentials`)

The test is a fork-owned integration test class that builds the GCS client directly. It does not edit
upstream's `classes/tests/test_file_system.php`. It is skipped without credentials. Every **[U]** above becomes
one assertion here:

1. HMAC mode: put / head / get / range / delete / copy with SDK 3.369 and `when_required`; then once with
   `when_supported` to record whether the defaults really fail (settles audit input 2).
2. Multipart above 16 MiB in HMAC **and** bearer modes: settles D26 and the (a+)-to-(b) fallback.
3. A null ACL on a bucket with uniform bucket-level access: no header sent, upload succeeds; with
   `x-amz-acl: private`, record the error (D4).
4. `PutObjectTagging`: record the error (D3).
5. `x-goog-hash` present on an XML HEAD for single-part and multipart objects (MD5 absent on the latter).
6. V4 URL with `response-content-disposition` signed: filename honoured (D11).
7. `signBlob` signing for its own service account with `iam.serviceAccounts.signBlob` granted on itself (D12).
8. Region `auto` versus the bucket location in SigV4 and V4 credential scopes (D6, D11).
9. Metadata mode on a Cloud Run job or GCE VM (D9 on GCE, `/email` path).

CDN and load balancer (a test project with a load balancer; a manual runbook in `infrastructure.md`, results
recorded in `docs/gcp/`):

1. Signed URL: 200, then a second request is a cache hit (the `cdn_cache_status` log field). Expired URL gives
   403; wrong key name gives 403.
2. `response-content-disposition` through the CDN with and without the include list: settles 3.2 item 2.
3. A Range request through the CDN on a 50 MB video: 206.
4. The load-balancer check on the real site: OK only after `reverseproxyignore` is set.
5. Cloud Armor in preview for a week of real traffic; read the would-be blocks before enforcing.

## 7. Staged plan (one pull request per stage, each `mdl ci --matrix --behat` locally)

| Stage | Content | Gates | Size |
|---|---|---|---|
| **G0** | Owner decisions O1-O4 taken 2026-10-15. A GCP test project, bucket and service account exist. Run real-bucket items 1-5 by hand with the current S3 store to settle the [U] items before code. | none | owner + ~1 h |
| **G1** | `gcs` store, HMAC mode only: subclass, hardening, `verify_object` by hash, `test_connection`, range proxy without presign, settings, P16 + P17, doubles, integration class, migration note. Signed URLs in G1 are the inherited SDK SigV4 presign with HMAC, which GCS accepts on the XML endpoint [D1, D11]; the upstream `connection` check covers the bucket until G4. | PHPUnit, mutation sweep; real-bucket items 1-5 when a test project exists | ~600 lines |
| **G2** | Keyless authentication: token providers, bearer middleware, native V4 signer (signer follows the mode; section 9), signed-URL cache. If real-bucket item 2 fails under bearer, **stop**: the native data path (b) becomes its own design. | `security-diff-read` + `!mdlshield review full` (authentication, file serving), real-bucket items 6-9 | ~500 lines |
| **G3** | Cloud CDN signer, routing rule, CDN settings, `gcs_cachecontrol`, optional cache-control backfill CLI, E2 (P18). | `security-diff-read` + MDL Shield (file serving), CDN runbook items 1-3 | ~300 lines |
| **G4** | `gcp_load_balancer` and `gcs_bucket` checks with E3 (P19) and `docs/gcp/infrastructure.md` (gcloud + Terraform for the bucket, IAM, backend bucket + keys + include list, the two Cloud Armor policies, firewall). | PHPUnit; runbook items 4-5 | ~250 lines + docs |
| **G5** | Production soak on one site. Afterwards offer upstream: the store as one pull request, and the check separately. | owner | — |

## 8. Owner decisions

Decided by the owner on 2026-10-15:

- **O1 = (a+)**: the `gcs` subclass of the S3 client, with the G2 fallback rule. (b) only if the bearer-token
  multipart test fails.
- **O2 = E2 approved**: the signing select reads its choices from the client (P18, lands in G3 with the CDN
  signer).
- **O3 = E3 approved**: the GCS and load-balancer checks register in System status (P19, lands in G4).
- **O4 = routing rule as proposed**: attachments to V4, inline media to the CDN, `gcs_cdn_filenames` off by
  default.

Still open (proposed defaults apply until the owner says otherwise):

- **O5** Default `gcs_authmode` when G2 adds it: `metadata` on a new install, but `hmac` on a site that already
  has `gcs_hmac_key` set, so that the G2 upgrade cannot switch a running G1 site away from its keys.
  `gcs_cachecontrol` empty by default.
- **O6** `lang/pt_br`: none; the plugin stays English-only like upstream.

## 9. What implementation changed (G1, G2, G3)

- **Measured: the null ACL.** The SDK sends a null `ACL` as an empty `x-amz-acl` header on a single PutObject. The
  multipart path drops it. The store unsets the parameter in `before_upload` (section 2.3).
- **Measured: V4 location `auto`.** Google's own V4 conformance vectors sign with location `auto`. The signer
  matches five of them byte for byte (canonical request, string to sign, URL). D11's open point is settled for V4.
- **Simplified: no `gcs_signer` setting.** The signer follows `gcs_authmode`. A separate HMAC signer for OAuth
  sites only saved the `signBlob` latency, and the signature cache already covers that.
- **Simplified: no separate files.** The bearer middleware is a closure in `set_client()`. The Content-Disposition
  rule is `client::normalise_content_disposition()`, not a copy of upstream's private `get_nice_filename()`.
  - Names that are pure ASCII pass through unchanged.
  - Other UTF-8 names become an ASCII fallback plus RFC 6266 `filename*`.
  - Names that are not UTF-8 keep only the fallback.
  - Whether Cloud Storage honours `filename*` in `response-content-disposition` is real-bucket item 6.
- **Security read of the G2 diff** (`security-diff-read`, 2026-10-16). Four fixes, each with a test and a mutation
  gate:
  - **F1:** Google's error text, which can name the service account, goes to the exception's debug info. The shown
    message names only the step (token endpoint, metadata server, key file, signBlob). The admin connection test
    still prints the debug info.
  - **F2:** a cached signature is never returned past the expiry the caller asked for.
  - **F3:** the service-account email is checked against address characters before it becomes a signBlob path
    segment.
  - **F5:** a file name that is not UTF-8 keeps only the ASCII fallback.
- **Two points from that read are documented, not changed:**
  - **F4:** a configured `gcs_serviceaccount` that differs from the attached account signs with the attached
    account's token. The attached account then needs Service Account Token Creator on the configured one.
  - **F6:** access tokens sit in the shared application cache (`gcstoken`, an ad-hoc definition) for up to an
    hour. On a Redis or Memcached store they are readable by anyone who can read that store, which is the same
    trust as the session store.
- **G3: the signing select.** The CDN is chosen in upstream's own `signingmethod` select (E2, P18), whose choices
  the store supplies, rather than in a store-specific `gcs_signingmethod`. The CDN settings appear in the store's
  section once `cdn` is saved. A CDN missing its URL, key name or key falls back to Cloud Storage URLs; settings
  that are present but unusable (non-https URL, bad key name, key not 16 bytes) raise
  `settings:gcs:cdnmisconfigured`.
- **G3: CDN URL encoding.** The CDN signer encodes the query with `rawurlencode()`, which is what `\moodle_url`
  re-encodes to, so the signed part reaches the browser byte for byte (pinned by a test). The padded base64url
  signature's `=` leaves `\moodle_url` as `%3D`; Cloud CDN is expected to decode query values before comparing
  the signature. **[U]**: CDN runbook item 1.
- **G3: not built.** The `cli/gcs_set_cache_control.php` backfill is not built: only new objects get
  `gcs_cachecontrol`.
- **G3: security read of the diff** (`security-diff-read`, 2026-10-16). Three fixes, each with a test and a
  mutation gate:
  - **Unusable CDN settings:** settings that are present but unusable no longer throw for every file. The signer
    is built only after the routing rule chose the CDN, and a failure gives a Cloud Storage URL plus a developer
    notice.
  - **Routing extension:** it is taken from the whole quoted `filename`, because upstream's
    `get_filename_from_header()` stops at `; `.
  - **Cache-Control value:** `gcs_cachecontrol` accepts only directive characters, and a value holding anything
    else is not sent.
- **Residual, documented:** CDN edges may keep a cached object for the cache lifetime after Moodle deletes the
  file. No new URL can be issued for it, because Moodle stops granting access.
- **G4: check naming and placement.** The checks override `get_name()`, so their strings stay in the
  `settings:gcs:*` block instead of core's `check<class>` keys beside upstream's. The status report lists both
  checks only when the Cloud Storage store is selected (P19).
- **G4: the load-balancer check.** It compares core's own `getremoteaddr()` with the two entries the load balancer
  appended, rather than reading the settings alone. It reports:
  - NA without X-Forwarded-For;
  - ERROR when X-Forwarded-For is skipped or Client-IP is read;
  - ERROR when the load balancer's address is taken as the client's;
  - WARNING when fewer than two entries arrive, or when the address taken is an earlier, client-supplied entry
    (an over-broad `reverseproxyignore`).
- **G4: the bucket check** writes a probe object under `tool_objectfs_probe/` with its own random key on each run,
  verifies its reported digest, then deletes it.
- **G4: integration test in key-file mode.** The integration test also runs in key-file mode when its credentials
  name `gcs_keyfile`. That run settles the G2 rule: does multipart work under bearer tokens?
- **G4: security read of the diff** (`security-diff-read`, 2026-10-16). Four fixes, each with a test and a
  mutation gate:
  - **One probe key per run, deleted afterwards.** A shared key let concurrent runs read each other's object and
    met Cloud Storage's write limit on one name.
  - **Unexpected exceptions.** An unexpected exception in the bucket check is that check's ERROR, so the System
    status report never breaks.
  - **Escaping.** The probe details (store and SDK text) are escaped in the HTML summary.
  - **Ports.** X-Forwarded-For entries lose their ports the way `getremoteaddr()` drops them.
- **Documented, not changed:** the load-balancer check judges the request that shows the report. Opened directly
  on a backend with a forged X-Forwarded-For, it judges forged data. Only admins see it, and the OK text says the
  verdict holds for requests through the load balancer.

## Appendix A: documentation checks (2026-10-15)

Read through `docs.cloud.google.com` (`cloud.google.com` redirects there). Pages were read through a fetch tool
that summarises, so the rows paraphrase.

| # | Claim | Verdict | Source path |
|---|---|---|---|
| D1 | XML API at `storage.googleapis.com` accepts SigV4 (`AWS4-HMAC-SHA256`) with HMAC keys | verified | storage/docs/aws-simple-migration, storage/docs/interoperability |
| D2 | XML multipart: up to 10,000 parts, 5 MiB-5 GiB per part (minimum enforced at Complete), 5 TiB object; no preconditions in multipart | verified | storage/docs/multipart-uploads, storage/quotas |
| D3 | S3 object tagging | **not documented**; treat as unsupported, test | — |
| D4 | ACL operations fail with 400 under uniform bucket-level access; `x-amz-acl` on XML PUT not covered | partly | storage/docs/uniform-bucket-level-access |
| D5 | Native checksums are MD5 and CRC32C via `x-goog-hash` / `Content-MD5`; `x-amz-checksum-*` and trailers undocumented; `aws-chunked` needs `x-amz-decoded-content-length` | partly | storage/docs/xml-api/reference-headers |
| D6 | Path style and virtual-hosted style both work; virtual-hosted not for dotted bucket names; samples use region `auto` without saying how it is used | partly | storage/docs/request-endpoints |
| D7 | Resumable uploads: session URI valid one week, 256 KiB chunk multiple, status via `Content-Range: bytes */N` and 308 | verified | storage/docs/resumable-uploads |
| D8 | JSON object fields `size`, `md5Hash` (absent for composite/CMEK), `crc32c`, `generation`; `ifGenerationMatch=0` | verified | storage/docs/json_api/v1/objects, storage/docs/request-preconditions |
| D9 | Metadata token endpoint with `Metadata-Flavor: Google`, fields `access_token`, `expires_in`, `token_type`; same path on Cloud Run and GKE Workload Identity Federation | verified (GCE page truncated) | compute/docs/access/authenticate-workloads, run/docs/securing/service-identity |
| D10 | Service-account JWT RS256 to `oauth2.googleapis.com/token`, `jwt-bearer` grant, `devstorage.*` scopes, 1 h maximum | verified | developers.google.com/identity/protocols/oauth2/service-account |
| D11 | V4: `GOOG4-RSA-SHA256` / `GOOG4-HMAC-SHA256`, `X-Goog-*` parameters, `X-Goog-Expires` ≤ 604800, location "any value"; `response-content-*` overrides not documented | partly | storage/docs/authentication/signatures |
| D12 | `signBlob` endpoint, `payload` / `keyId` / `signedBlob`, permission `iam.serviceAccounts.signBlob`; self-signing and quota not documented | partly | iam/docs/reference/credentials/rest/v1/projects.serviceAccounts/signBlob |
| D13 | `Cache-Control` settable; `public, max-age=3600` default only for publicly readable objects | partly | storage/docs/metadata |
| D14 | Cloud CDN signed URL `Expires`, `KeyName`, `Signature`; HMAC-SHA1, raw 16-byte key, base64url; 3 keys per backend; `--signed-url-cache-max-age` default 1 h | verified | cdn/docs/using-signed-urls |
| D15 | URL prefix signing (`URLPrefix`, `Expires`, `KeyName`, `Signature`) | verified | cdn/docs/using-signed-urls |
| D16 | Signed cookie `Cloud-CDN-Cookie` format | verified | cdn/docs/using-signed-cookies |
| D17 | Signing parameters stripped before the origin; backend-bucket cache key keeps only Cloud Storage-recognised parameters; include list only | partly (cache-key treatment of the signing parameters not stated) | cdn/docs/using-signed-urls, cdn/docs/using-cache-keys |
| D18 | Private bucket through `cloud-cdn-fill` account with `objectViewer`; cache modes; signed content may be cached regardless of `Cache-Control` | verified | cdn/docs/using-signed-urls, cdn/docs/caching |
| D19 | Range requests: strong `ETag`, `Accept-Ranges`, up to 100 GiB cacheable; gzip over 10 MB risk | verified | cdn/docs/caching |
| D20 | Cloud CDN has only HMAC-SHA1 URLs and cookies (Ed25519 is Media CDN) | partly | media-cdn/docs/choose-cdn-product (search snippet) |
| D21 | `X-Forwarded-For: [<supplied>,]<client-ip>,<lb-ip>`; `X-Forwarded-Proto` | verified | load-balancing/docs/https |
| D22 | Edge policies are the only type on backend buckets and run before the cache, with limited attributes; backend policies only on cache misses | verified | armor/docs/security-policy-overview |
| D23 | CRS 4.22 rule sets (`*-v422-stable`), sensitivity 0-4, body inspection 64 kB, `throttle` / `rate_based_ban`, WAF only on backend services | verified (Adaptive Protection not read) | armor/docs/waf-rules, armor/docs/rate-limiting-overview |
| D24 | Custom request header variables incl. `{client_ip_address}`; backend buckets take no custom request headers | verified | load-balancing/docs/https/custom-headers |
| D25 | WAF opt-out and field-exclusion syntax; rules with exclusions cannot `allow` | verified | armor/docs/rule-tuning |
| D26 | XML API accepts OAuth bearer tokens (samples for PUT, multipart initiate, list); `ListBuckets` needs `x-goog-project-id` | partly (no per-operation list) | storage/docs/authentication, storage/docs/xml-api/* |
| D27 | XML PUT takes `Cache-Control`, `Content-Disposition`; `x-goog-if-generation-match` documented generally; `If-None-Match: *` not documented | partly | storage/docs/xml-api/put-object-upload |
| D28 | 10 HMAC keys per service account; HMAC works on the XML API only; Google recommends ADC / attached service accounts | partly | storage/docs/authentication/hmackeys |
