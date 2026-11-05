# Cloudflare cache setup

Use [cache-rule.json](cache-rule.json) for a proxied timer hostname. Replace
`timer.example.com` in both the expression and description. The payload matches
the deployment-verified reference policy; verify behavior for your installation.
Other rules, Workers and zone settings can override it. Keep full query strings
in cache keys and disable query sorting for this host, including any zone-level
Query String Sort setting. A custom cache key that excludes parameters is unsafe.

## Dashboard

1. Select the zone, then **Caching → Cache Rules → Create rule**. Use the
   description from the JSON as the rule name.
2. Choose a custom filter expression, **Edit expression**, and paste the JSON's
   `expression` value with your hostname substituted. This selects only GET/HEAD,
   `/` or `/index.php`, and at least one of `time`, `evergreen`, `relative`, `preset`.
3. Set **Cache eligibility: Eligible for cache** (`cache: true`).
4. **Edge TTL: Use cache-control header if present, use default Cloudflare caching behavior if not**
   (`respect_origin`). Add a status-code TTL for range **400–599: No store**
   (`value: -1`, not zero/no-cache). Do not set a fixed successful-response TTL.
5. **Browser TTL: Respect existing headers** (`respect_origin`).
6. Keep the default cache key with **all query parameters**. Set **Sort query
   string: Off** (`ignore_query_strings_order: false`). Do not exclude `key`,
   `uid`, `uidCampaign`, time, timezone or appearance parameters.
7. Save and inspect the effective rule through the API/export. If your plan or
   dashboard does not expose a setting, use the API below and stop on rejection;
   do not silently replace it with a different TTL or cache key. Review order
   against other matching rules, then run the verification below.

## API

Requires Bash, curl and jq. Create a temporary API token with **Zone → Cache
Rules: Edit** and **Zone → Cache Purge: Purge**, scoped to **one specific zone**.
Set an expiration and remove the token after setup/verification. Supply
`CF_API_TOKEN` through your secret manager and set `ZONE_ID` to that zone's ID.
Never enable shell tracing or save real keys/tokens in repository evidence.
All response files below belong in a private working directory outside the repo.

From the repository root, prepare a private payload and backup:

```bash
set -euo pipefail
umask 077
: "${CF_API_TOKEN:?Supply through your secret manager}"
: "${ZONE_ID:?Select exactly one zone}"
TIMER_HOST=timer.example.com
CF_BASE="https://api.cloudflare.com/client/v4/zones/$ZONE_ID"
WORK_DIR=$(mktemp -d)
jq --arg host "$TIMER_HOST" '
  .expression |= gsub("timer\\.example\\.com"; $host) |
  .description |= gsub("timer\\.example\\.com"; $host)
' ops/cloudflare/cache-rule.json > "$WORK_DIR/rule.json"
cf() {
  curl --fail-with-body --silent --show-error --max-time 40 \
    --header "Authorization: Bearer $CF_API_TOKEN" \
    --header 'Content-Type: application/json' "$@"
}
HTTP_STATUS=$(curl --silent --show-error --max-time 40 \
  --header "Authorization: Bearer $CF_API_TOKEN" \
  --output "$WORK_DIR/entrypoint.json" --write-out '%{http_code}' \
  "$CF_BASE/rulesets/phases/http_request_cache_settings/entrypoint")
echo "entrypoint HTTP $HTTP_STATUS"
```

Inspect the response. **200** means an entrypoint exists. **404** must specifically
mean the phase entrypoint is absent; check the JSON errors and selected zone.
Stop on authentication, permission, zone or any other error. Do not turn a failed
read into a create operation. Follow exactly one branch:

**Existing entrypoint (200):** append one rule, preserving all existing rules.
Inspect the backup for an equivalent rule first; stop instead of adding duplicates.
Do not PUT the entire ruleset.

```bash
test "$HTTP_STATUS" = 200
jq -e '.success == true' "$WORK_DIR/entrypoint.json"
RULESET_ID=$(jq -er '.result.id' "$WORK_DIR/entrypoint.json")
cf --request POST --data-binary "@$WORK_DIR/rule.json" \
  "$CF_BASE/rulesets/$RULESET_ID/rules" > "$WORK_DIR/added.json"
jq -e '.success == true' "$WORK_DIR/added.json"
```

**Absent entrypoint (confirmed 404):** create the zone phase entrypoint with only
this rule. This is not a replacement for an existing ruleset. If another operator
creates it concurrently, stop, reread and follow the existing-entrypoint branch.

```bash
test "$HTTP_STATUS" = 404
jq -s '{name:"Countdown cache rules", kind:"zone",
  phase:"http_request_cache_settings", rules:.}' \
  "$WORK_DIR/rule.json" > "$WORK_DIR/create.json"
cf --request POST --data-binary "@$WORK_DIR/create.json" \
  "$CF_BASE/rulesets" > "$WORK_DIR/added.json"
jq -e '.success == true' "$WORK_DIR/added.json"
RULESET_ID=$(jq -er '.result.id' "$WORK_DIR/added.json")
```

For either branch, reread, record the new rule ID by its unique description, and
compare its expression and action parameters with `rule.json`. For an existing
entrypoint, compare the other rules with the backup; they must be unchanged.

```bash
cf "$CF_BASE/rulesets/$RULESET_ID" > "$WORK_DIR/after.json"
jq -e '.success == true' "$WORK_DIR/after.json"
DESCRIPTION=$(jq -r '.description' "$WORK_DIR/rule.json")
RULE_ID=$(jq -er --arg desc "$DESCRIPTION" '
  [.result.rules[] | select(.description == $desc)] |
  if length == 1 then .[0].id else error("expected exactly one timer rule") end
' "$WORK_DIR/after.json")
jq --arg id "$RULE_ID" '.result.rules[] | select(.id == $id) |
  del(.id,.version,.last_updated,.ref)' "$WORK_DIR/after.json" > "$WORK_DIR/actual.json"
diff -u <(jq -S . "$WORK_DIR/rule.json") <(jq -S . "$WORK_DIR/actual.json")
if [ "$HTTP_STATUS" = 200 ]; then
  diff -u <(jq -S '.result.rules' "$WORK_DIR/entrypoint.json") \
    <(jq -S --arg id "$RULE_ID" '[.result.rules[] | select(.id != $id)]' "$WORK_DIR/after.json")
fi
jq -n --arg host "$TIMER_HOST" '{hosts:[$host]}' > "$WORK_DIR/purge.json"
cf --request POST --data-binary "@$WORK_DIR/purge.json" \
  "$CF_BASE/purge_cache" | jq -e '.success == true'
```

Purge **only this hostname**, never the whole zone. Keep the recorded IDs and
backup privately for rollback. Hostname purge availability depends on your
Cloudflare plan; verify it before enabling the rule. If unavailable, stop and
agree an equally bounded purge method before proceeding.

## Verify HTTP behavior

Use a dedicated test key. Run these from one client/edge without exceeding the
origin's 30 requests/minute. Do not save query keys in evidence. `curl` below
prints response headers only, not request URLs. Use a unique probe value so the
first response is cold. A finished timer makes the MISS/HIT check independent
of a short active bucket:

```bash
: "${TIMER_KEY:?Supply a dedicated test key privately}"
PROBE=$(date +%s)
BASE="https://$TIMER_HOST/"
gif_headers() {
  curl --silent --show-error --max-time 30 --get --dump-header - --output /dev/null \
    --data-urlencode 'time=2020-01-01' --data-urlencode 'preset=dark-boxes' \
    --data-urlencode "probe=$PROBE" --data-urlencode "key=$1" "$BASE"
}
gif_headers "$TIMER_KEY"   # 200 MISS (or EXPIRED if already present)
sleep 1
gif_headers "$TIMER_KEY"   # 200 HIT, Age increasing; finished TTL 86400
gif_headers INVALID_TEST_KEY  # 403, never HIT, no Age
gif_headers INVALID_TEST_KEY  # 403 again, never HIT
for attempt in 1 2; do
  curl --silent --show-error --get --dump-header - --output /dev/null \
    --data-urlencode 'time[]=bad' --data-urlencode "key=$TIMER_KEY" "$BASE"
  curl --silent --show-error --dump-header - --output /dev/null "$BASE"
  curl --silent --show-error --dump-header - --output /dev/null "$BASE/absent"
done
PROBE="${PROBE}-different"
gif_headers "$TIMER_KEY"   # new query: own MISS
gif_headers "$TIMER_KEY"   # own HIT
```

Expected: malformed input **400 without HIT**, missing route **404 without HIT**,
landing **200 DYNAMIC without Age**. Repeat a valid warmed URL with `key` omitted
and verify 403 without HIT. A different probe must have its own cold entry even
when pixels match. Also test distinguishable UID deadlines (e.g. 2h and 3h) and
campaigns, each with its own repeat. Reordering the same query parameters must
have a separate cold entry when sorting is disabled.

For active timers, use a fixed future absolute deadline and `seconds=30`,
download bytes and ETag within TTL and after expiry: within TTL they stay equal;
after expiry the first-frame values/ETag change. Active TTL is bounded by the
bucket, deadline and effective seconds (maximum 120, active preview maximum 5).
Finished previews also use 86400 seconds. Coordinate a separate 429 check with
fresh query variants to reach origin; the 429 and its repeat must never HIT.
`s-maxage` alone and a filesystem `X-Cache: HIT` do not establish CDN caching.

A CDN HIT skips origin authentication, the IP limiter and generation accounting.
Revoking a key requires a timer-host purge. CDN copies may otherwise remain for
up to 24 hours for finished timers; client/image-proxy copies follow their own
caching behavior and survive a CDN purge. A finite GIF cannot refresh an open email.

## Roll back only this rule

Reread the current entrypoint and identify the rule by the exact description and
recorded ID above. Inspect its hostname/expression before DELETE. If multiple
matches exist, stop. Preserve all other rules, including any added since setup.

```bash
cf "$CF_BASE/rulesets/$RULESET_ID" > "$WORK_DIR/before-delete.json"
jq -e --arg id "$RULE_ID" --arg desc "$DESCRIPTION" \
  '[.result.rules[] | select(.id == $id and .description == $desc)] | length == 1' \
  "$WORK_DIR/before-delete.json"
cf --request DELETE "$CF_BASE/rulesets/$RULESET_ID/rules/$RULE_ID" \
  | jq -e '.success == true'
cf --request POST --data-binary "@$WORK_DIR/purge.json" \
  "$CF_BASE/purge_cache" | jq -e '.success == true'
cf "$CF_BASE/rulesets/$RULESET_ID" > "$WORK_DIR/after-delete.json"
jq -e '.success == true' "$WORK_DIR/after-delete.json"
diff -u <(jq -S --arg id "$RULE_ID" '[.result.rules[] | select(.id != $id)]' "$WORK_DIR/before-delete.json") \
  <(jq -S '.result.rules' "$WORK_DIR/after-delete.json")
gif_headers "$TIMER_KEY"
gif_headers "$TIMER_KEY"
gif_headers INVALID_TEST_KEY
```

Both valid repeats must be **DYNAMIC without Age**; invalid key stays 403 without
HIT. If purge fails after DELETE, retry the host purge separately; do not repeat
DELETE or widen purge. If responses still HIT, inspect other matching rules.
Remove the temporary API token and private working files after retaining the
required sanitized operational record. This does not remove copies in clients.
