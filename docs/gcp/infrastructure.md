# Google Cloud infrastructure for the GCS store

This runbook builds what the `gcs` store needs (docs/gcp/DESIGN.md) and the checks that prove it works:

- a private bucket with a service account and its credentials;
- Cloud CDN in front of the bucket, with signed URLs;
- the Moodle side, on a dev stack first.

Section 5 covers the production load balancer and Cloud Armor.

Every gcloud flag below was checked against the help of Google Cloud SDK 583.0.0. Anything not confirmed that way
is marked **[U]** and becomes a result to record here.

## 0. Where to run the commands

- **GCP commands: Cloud Shell** (console, top bar). It already has an authenticated gcloud and is not behind the
  office proxy.
- **On this Mac, gcloud 583 needs a workaround.** `gcloud storage ...` crashes there with "module 'ssl' has no
  attribute 'PROTOCOL_TLSv1'". The bundled Python 3.14 virtualenv carries an old SSL module. Run gcloud with the
  Homebrew Python instead:

  ```sh
  export CLOUDSDK_PYTHON=/opt/homebrew/bin/python3.15
  ```

  Behind the corporate proxy, also set `CLOUDSDK_PROXY_TYPE`, `CLOUDSDK_PROXY_ADDRESS`, `CLOUDSDK_PROXY_PORT` and the
  raw (not URL-encoded) `CLOUDSDK_PROXY_USERNAME` / `CLOUDSDK_PROXY_PASSWORD`.
- **Moodle and Docker commands: this Mac** (`mdl`, `docker`).

Set these once per shell. The values are examples; `CDNHOST` must be a name whose DNS you control.

```sh
PROJECT=my-moodle-test
REGION=southamerica-east1
BUCKET=my-moodle-test-objectfs
SA=moodle-objectfs
SAEMAIL=$SA@$PROJECT.iam.gserviceaccount.com
CDNHOST=cdn-test.example.org
gcloud config set project $PROJECT
gcloud services enable storage.googleapis.com compute.googleapis.com iamcredentials.googleapis.com
```

## 1. Bucket and service account

```sh
gcloud storage buckets create gs://$BUCKET --location=$REGION --uniform-bucket-level-access --public-access-prevention
gcloud iam service-accounts create $SA --display-name="Moodle objectfs"
gcloud storage buckets add-iam-policy-binding gs://$BUCKET \
    --member=serviceAccount:$SAEMAIL --role=roles/storage.objectAdmin
```

- **No project-level role.** The store only needs bucket-scoped rights. Its connection test reads a sentinel object
  and never lists buckets.
- **Public access prevention stays on.** It blocks only `allUsers` / `allAuthenticatedUsers`, never the Cloud CDN
  fill account (section 2).
- **Abort abandoned multipart uploads.** Cloud Storage puts no limit on how long one stays open. **[U]** the
  lifecycle action name.

  ```sh
  cat > lifecycle.json <<'EOF'
  {"rule": [{"action": {"type": "AbortIncompleteMultipartUpload"}, "condition": {"age": 7}}]}
  EOF
  gcloud storage buckets update gs://$BUCKET --lifecycle-file=lifecycle.json
  ```

Credentials, one or both:

```sh
# HMAC key (authentication "HMAC key"): prints the access ID and the secret ONCE; keep the secret.
gcloud storage hmac create $SAEMAIL

# Key file (authentication "Service-account key file"). An organisation policy
# (iam.disableServiceAccountKeyCreation) may forbid it; then use HMAC on the dev stack.
gcloud iam service-accounts keys create gcs-key.json --iam-account=$SAEMAIL
```

The third mode, the attached service account through the metadata server, only exists on GCE, GKE and Cloud Run.
A Mac dev stack cannot test it. In production it also needs Service Account Token Creator on the account itself,
for `signBlob`:

```sh
gcloud iam service-accounts add-iam-policy-binding $SAEMAIL \
    --member=serviceAccount:$SAEMAIL --role=roles/iam.serviceAccountTokenCreator
```

## 2. Cloud CDN: a backend bucket behind an external Application Load Balancer

A Google-managed certificate needs `CDNHOST` to resolve to the load balancer. Without a name you control, use a
self-managed certificate (`--certificate`, `--private-key`) and test with `curl -k` only: browsers would refuse the
redirect.

```sh
gcloud compute addresses create objectfs-cdn-ip --global
gcloud compute addresses describe objectfs-cdn-ip --global --format='value(address)'
# Create the DNS A record CDNHOST -> that address now.

gcloud compute backend-buckets create objectfs-cdn --gcs-bucket-name=$BUCKET --enable-cdn --cache-mode=CACHE_ALL_STATIC

# Signing key: 16 random bytes, base64url. The same text goes into Moodle's "Signing key".
head -c 16 /dev/urandom | base64 | tr '+/' '-_' > cdn-key.txt
gcloud compute backend-buckets add-signed-url-key objectfs-cdn --key-name=moodle-key-1 --key-file=cdn-key.txt

# The Cloud CDN fill account exists only once a key has been added; it reads the private bucket.
PROJNUM=$(gcloud projects describe $PROJECT --format='value(projectNumber)')
gcloud storage buckets add-iam-policy-binding gs://$BUCKET \
    --member=serviceAccount:service-$PROJNUM@cloud-cdn-fill.iam.gserviceaccount.com --role=roles/storage.objectViewer

gcloud compute url-maps create objectfs-cdn-map --default-backend-bucket=objectfs-cdn
gcloud compute ssl-certificates create objectfs-cdn-cert --domains=$CDNHOST --global
gcloud compute target-https-proxies create objectfs-cdn-proxy --url-map=objectfs-cdn-map --ssl-certificates=objectfs-cdn-cert
gcloud compute forwarding-rules create objectfs-cdn-https --global --load-balancing-scheme=EXTERNAL_MANAGED \
    --address=objectfs-cdn-ip --target-https-proxy=objectfs-cdn-proxy --ports=443

# Wait until the certificate is ACTIVE (provisioning can take a while after DNS resolves).
gcloud compute ssl-certificates describe objectfs-cdn-cert --global --format='value(managed.status)'
```

The step below is needed only for CDN check 2 (section 4.6). It puts the download name in the cache key, the
precondition for the store's "File names through the CDN" setting:

```sh
gcloud compute backend-buckets update objectfs-cdn \
    --cache-key-query-string-whitelist=response-content-disposition,response-content-type
```

## 3. Moodle dev stack (m503b)

**Use m503b, not m503.** It is the second 5.3 copy, so a mistake does not take down the stack other sessions work
on. Steps 3.1 and 4.7 change a shared stack: ask the other sessions first (`ListAgents` plus a message, or the
owner).

### 3.1 Mount the 5.3 checkout (once)

`plugins.conf` still mounts the frozen 5.1 checkout (`moodle-tool_objectfs`, `MOODLE_501_STABLE`) on m501, m502 and
m502b. Point it at the 5.3 checkout. Its `$plugin->supported = [503, 503]` then mounts it on m503 and m503b, and
removes it from the 5.1 and 5.2 stacks.

1. In `~/dev/moodle-dev/plugins.conf`, change
   `tool_objectfs|moodle-tool_objectfs|admin/tool/objectfs|auto` to
   `tool_objectfs|moodle-tool_objectfs-503|admin/tool/objectfs|auto`.
2. Run `mdl mounts`, then `mdl up m503b` (and `mdl up m503`).
3. Run `mdl upgrade m503b`, then `mdl phpunit-init m503b`.
4. On m501, m502 and m502b, uninstall the plugin that lost its files. Run the dry-run first:
   `php admin/cli/uninstall_plugins.php --purge-missing`, then the same command with `--run` (inside `mdl sh <stack>`).

### 3.2 Configure the store (do not switch the site's file system yet)

Leave `$CFG->alternative_file_system_class` unset. The stack's own files stay local, other sessions are not
affected, and the store's test page and integration tests still exercise the bucket. Run the settings inside the
container with `mdl sh m503b`, from `/var/www/html`:

```sh
C="php admin/cli/cfg.php --component=tool_objectfs"
$C --name=filesystem --set='\tool_objectfs\gcs_file_system'
$C --name=gcs_bucket --set=my-moodle-test-objectfs
$C --name=key_prefix --set=m503b/
$C --name=gcs_authmode --set=hmac
$C --name=gcs_hmac_key --set=GOOG1E...
$C --name=gcs_hmac_secret --set=...
$C --name=enablepresignedurls --set=1
$C --name=gcs_cachecontrol --set='private, max-age=31536000, immutable'
```

- **Key-file mode instead of HMAC.** Copy the key into the container's data directory, then point the store at it:

  ```sh
  docker exec m503b-webserver-1 mkdir -p /var/www/moodledata/objectfs
  docker cp gcs-key.json m503b-webserver-1:/var/www/moodledata/objectfs/gcs-key.json
  docker exec m503b-webserver-1 chown -R www-data:www-data /var/www/moodledata/objectfs
  ```

  Then set `gcs_authmode=keyfile` and `gcs_keyfile=objectfs/gcs-key.json` (relative to the data directory).
- **Behind the corporate proxy,** also set `useproxy=1`. The site's own proxy settings must already be correct.

Then open http://localhost:9503/admin/settings.php?section=tool_objectfs_settings. The **Connection status** check
next to the store settings must be OK.

### 3.3 CDN settings

Run these in the same shell:

```sh
$C --name=signingmethod --set=cdn
$C --name=gcs_cdn_baseurl --set=https://cdn-test.example.org
$C --name=gcs_cdn_keyname --set=moodle-key-1
$C --name=gcs_cdn_key --set="$(cat cdn-key.txt)"
```

`cdn-key.txt` comes from section 2: copy its content, or the file, into the container.

## 4. Checks, and where to record them

Record each result in `docs/gcp/results-YYYY-MM-DD.md` (pass/fail and the exact output that decided it). These are
the real-bucket items of DESIGN.md section 6.2 and the CDN runbook items.

### 4.1 Integration tests (HMAC)

Add the credentials to `~/dev/moodle-503b/config.php`, above the `require_once` of `setup.php`:

```php
$CFG->phpunit_objectfs_gcs_integration_test_credentials = [
    'gcs_bucket' => 'my-moodle-test-objectfs',
    'gcs_hmac_key' => 'GOOG1E...',
    'gcs_hmac_secret' => '...',
];
```

Then, from this Mac:

```sh
mdl phpunit m503b admin/tool/objectfs/tests/local/store/gcs/integration_test.php
```

Five tests must pass: connection, single upload by digest, multipart (two parts) by CRC32C, range read, and signed
URL with file name. Together they settle the real-bucket items listed below. Item 6 covers the HMAC SigV4 URL only.

| Item | What it settles |
|---|---|
| 1 | SDK checksums `when_required`, `NoSuchKey` / `NoSuchBucket` codes |
| 2 (HMAC half) | multipart upload with HMAC |
| 3 | no ACL with uniform bucket-level access |
| 5 | `x-goog-hash` on HEAD |
| 6 (SigV4 only) | download name in a signed URL |

### 4.2 Key-file mode (bearer tokens; DESIGN.md G2 rule)

Run 4.1 again with the key file in place of the HMAC pair. This settles real-bucket item 2's bearer half: whether
multipart uploads work under OAuth. If the multipart test fails here and passes in 4.1, the G2 rule applies, and the
data path of the OAuth modes moves to the native store.

```php
$CFG->phpunit_objectfs_gcs_integration_test_credentials = [
    'gcs_bucket' => 'my-moodle-test-objectfs',
    'gcs_keyfile' => '/var/www/moodledata/objectfs/gcs-key.json',
];
```

The PHPUnit process runs inside the container, so the path is the container's (section 3.2 copies the file there).
The signed-URL test then checks a V4 URL signed by the key instead of SigV4.

### 4.3 Pre-signed URL test page

Open http://localhost:9503/admin/tool/objectfs/presignedurl_tests.php as admin.

- With `signingmethod=cdn`, the inline video fixture must redirect to `https://CDNHOST/m503b/...?Expires=...&KeyName=...&Signature=...`.
- The downloads must redirect to `https://storage.googleapis.com/...`.
- Copy one CDN URL from the browser's network panel for 4.4.

### 4.4 CDN check 1: signature and caching

```sh
URL='<the CDN URL copied from the browser>'
curl -sI "$URL" | head -5
curl -sI "$URL" | grep -i '^age:'
```

- **First request:** 200.
- **Second request:** an `Age:` header shows a cache hit. **[U]**: Cloud CDN exposes the hit this way; otherwise
  read `httpRequest.cacheHit` in the load balancer logs.
- **The URL is used exactly as Moodle produced it,** with the signature's `=` sent as `%3D`. A 200 settles DESIGN.md
  section 9's open point.
- **Expiry:** change the `Expires=` digits or wait past them; the answer must be 403.
- **Signature:** change one character of `Signature=`; the answer must be 403.

### 4.5 CDN check 3: range requests

```sh
curl -s -o /dev/null -w '%{http_code} %{size_download}\n' -r 0-999 "$URL"
```

Expect `206 1000`.

### 4.6 CDN check 2: file names through the CDN (optional)

1. Run the `--cache-key-query-string-whitelist` command from section 2.
2. Set `gcs_cdn_filenames=1`.
3. Repeat 4.4 with two Moodle files that share content but have different names.
4. Each must download under its own name.
5. Without the include list, the second would receive the first one's name. That is why the setting is off by
   default.

### 4.7 End-to-end on the live stack (optional, announce first)

This switches every file of m503b to the store, for every session using that stack.

1. Add `$CFG->alternative_file_system_class = '\tool_objectfs\gcs_file_system';` to `~/dev/moodle-503b/config.php`.
2. Set `enabletasks=1`.
3. Push files with
   `php admin/cli/scheduled_task.php --execute='\tool_objectfs\task\push_objects_to_storage'`.
4. Play a video in a course; the network panel must show the redirect to `CDNHOST`.
5. Undo by removing the config line. Files stay local while `deletelocal` is off.

## 5. Production: load balancer in front of Moodle, and Cloud Armor

- **Moodle's client-IP settings:**
  - `$CFG->reverseproxy` must stay off, because the load balancer keeps the `Host` header.
  - `$CFG->sslproxy` must be on when the load balancer talks HTTP to the backends.
  - `getremoteaddrconf` must skip only `HTTP_CLIENT_IP`.
  - `reverseproxyignore` must list the load balancer's frontend IP, because core takes the last
    `X-Forwarded-For` entry.

  The `gcp_load_balancer` status check (stage G4) verifies all of this from a browser request.
- **Backends** accept traffic only from Google's load-balancer source ranges. **[U]**: read the current ranges from
  Google's firewall documentation when deploying.
- **Cloud Armor, backend policy on the Moodle backend service:**
  - Preconfigured WAF on CRS 4.22 (`sqli-v422-stable`, `xss-v422-stable`, `lfi-v422-stable`, `rce-v422-stable`, ...).
  - Every rule starts in preview mode; read the would-be blocks for a week before enforcing.
  - Rate-limit `/login/` and `/webservice/`.
  - Add field exclusions for the editor endpoints (DESIGN.md section 4.2).
- **Cloud Armor, edge policy on the CDN backend bucket:** geo/IP and method rules only. Preconfigured WAF does not
  apply to backend buckets.

## 6. Cleanup of the test project

```sh
gcloud compute forwarding-rules delete objectfs-cdn-https --global
gcloud compute target-https-proxies delete objectfs-cdn-proxy
gcloud compute ssl-certificates delete objectfs-cdn-cert --global
gcloud compute url-maps delete objectfs-cdn-map
gcloud compute backend-buckets delete objectfs-cdn
gcloud compute addresses delete objectfs-cdn-ip --global
gcloud storage rm --recursive gs://$BUCKET
gcloud iam service-accounts delete $SAEMAIL
```

Also deactivate and delete the HMAC key (`gcloud storage hmac update ACCESS_ID --deactivate`, then
`gcloud storage hmac delete ACCESS_ID`), and delete the local `gcs-key.json` and `cdn-key.txt`.
