#!/usr/bin/env bash
BASE="http://127.0.0.1:8080/api"
JAR_DIR="$(mktemp -d)"
PASS=0; FAIL=0

hit() { # name method path expected [token] [body] [jar]
  local name="$1" method="$2" path="$3" expect="$4" token="$5" body="$6" jar="$7"
  local args=(-s -o /tmp/resp.json -w '%{http_code}' -X "$method" "$BASE$path" -H 'Accept: application/json')
  [ -n "$token" ] && args+=(-H "Authorization: Bearer $token")
  [ -n "$body" ] && args+=(-H 'Content-Type: application/json' -d "$body")
  [ -n "$jar" ] && args+=(-b "$JAR_DIR/$jar" -c "$JAR_DIR/$jar")
  local code; code=$(curl "${args[@]}")
  if [ "$code" = "$expect" ]; then PASS=$((PASS+1)); printf '  ok   %-3s %-46s %s\n' "$code" "$name" ""
  else FAIL=$((FAIL+1)); printf '  FAIL %-3s (want %s) %-34s %s\n' "$code" "$expect" "$name" "$(head -c 160 /tmp/resp.json)"; fi
}

login() { # email password jar -> prints token
  curl -s -c "$JAR_DIR/$3" -X POST "$BASE/auth/login" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d "{\"email\":\"$1\",\"password\":\"$2\"}" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("data",{}).get("accessToken",""))'
}
jget() { python3 -c "import sys,json;d=json.load(sys.stdin);
import functools
def dig(o,p):
  for k in p.split('.'):
    o = o[int(k)] if k.isdigit() else o[k]
  return o
print(dig(d,'$1'))"; }

echo "== HEALTH =="
hit "health" GET "/health" 200

echo
echo "== LOGIN =="
ADMIN=$(login admin@example.com 'Passw0rd!' admin.jar)
SAM=$(login sam@example.com 'Passw0rd!' sam.jar)
JORDAN=$(login jordan@example.com 'Passw0rd!' jordan.jar)
[ -n "$ADMIN" ] && { PASS=$((PASS+1)); echo "  ok   200 login admin"; } || { FAIL=$((FAIL+1)); echo "  FAIL login admin"; }
[ -n "$SAM" ] && { PASS=$((PASS+1)); echo "  ok   200 login moderator"; } || { FAIL=$((FAIL+1)); echo "  FAIL login moderator"; }
[ -n "$JORDAN" ] && { PASS=$((PASS+1)); echo "  ok   200 login user"; } || { FAIL=$((FAIL+1)); echo "  FAIL login user"; }
hit "login wrong password" POST "/auth/login" 401 "" '{"email":"jordan@example.com","password":"nope"}'
hit "login unknown address" POST "/auth/login" 401 "" '{"email":"ghost@example.com","password":"Passw0rd!"}'
hit "login deactivated account" POST "/auth/login" 403 "" '{"email":"dana@example.com","password":"Passw0rd!"}'
hit "login missing field" POST "/auth/login" 400 "" '{"email":"jordan@example.com"}'

echo
echo "== SESSION =="
hit "me as admin" GET "/auth/me" 200 "$ADMIN"
hit "me as moderator" GET "/auth/me" 200 "$SAM"
hit "me without token" GET "/auth/me" 401
hit "me with junk token" GET "/auth/me" 401 "not.a.jwt"

echo
echo "== TICKET LIST =="
hit "list default" GET "/tickets" 200 "$SAM"
hit "list page 2 limit 5" GET "/tickets?page=2&limit=5" 200 "$SAM"
hit "filter status" GET "/tickets?status=open,in_progress" 200 "$SAM"
hit "filter priority" GET "/tickets?priority=high,urgent" 200 "$SAM"
hit "filter category" GET "/tickets?category=it-access" 200 "$SAM"
hit "filter assignee=me" GET "/tickets?assignee=me" 200 "$SAM"
hit "filter assignee=unassigned" GET "/tickets?assignee=unassigned" 200 "$SAM"
hit "filter overdue" GET "/tickets?overdue=true" 200 "$SAM"
hit "search" GET "/tickets?search=vpn" 200 "$SAM"
hit "sort priority desc" GET "/tickets?sortBy=priority&sortDir=desc" 200 "$SAM"
hit "limit over ceiling" GET "/tickets?limit=1000" 400 "$SAM"
hit "unknown parameter" GET "/tickets?statuss=open" 400 "$SAM"
hit "unknown category slug" GET "/tickets?category=nope" 400 "$SAM"
hit "bad enum" GET "/tickets?status=banana" 400 "$SAM"
hit "list as user" GET "/tickets" 200 "$JORDAN"
hit "summary as moderator" GET "/tickets/summary" 200 "$SAM"
hit "summary as user" GET "/tickets/summary" 403 "$JORDAN"

echo
echo "== TICKET WRITE =="
CAT=$(curl -s "$BASE/categories" -H "Authorization: Bearer $JORDAN" -H 'Accept: application/json' | jget 'data.0.id')
NEW=$(curl -s -X POST "$BASE/tickets" -H "Authorization: Bearer $JORDAN" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"subject\":\"Smoke test ticket\",\"description\":\"Raised by the manual smoke run.\",\"categoryId\":\"$CAT\",\"priority\":\"high\"}")
NEWID=$(echo "$NEW" | jget 'data.id')
[ -n "$NEWID" ] && { PASS=$((PASS+1)); echo "  ok   201 create ticket ($NEWID)"; } || { FAIL=$((FAIL+1)); echo "  FAIL create ticket: $(head -c 200 <<<"$NEW")"; }
hit "create with short subject" POST "/tickets" 400 "$JORDAN" "{\"subject\":\"Hi\",\"description\":\"too short\",\"categoryId\":\"$CAT\"}"
hit "show own ticket" GET "/tickets/$NEWID" 200 "$JORDAN"
hit "show as moderator" GET "/tickets/$NEWID" 200 "$SAM"
SAMID=$(curl -s "$BASE/auth/me" -H "Authorization: Bearer $SAM" -H 'Accept: application/json' | jget 'data.user.id')
hit "assign as moderator" PATCH "/tickets/$NEWID" 200 "$SAM" "{\"assigneeId\":\"$SAMID\"}"
hit "assign as user (denied)" PATCH "/tickets/$NEWID" 403 "$JORDAN" "{\"assigneeId\":\"$SAMID\"}"
JORDANID=$(curl -s "$BASE/auth/me" -H "Authorization: Bearer $JORDAN" -H 'Accept: application/json' | jget 'data.user.id')
hit "assign to a non-agent" PATCH "/tickets/$NEWID" 422 "$SAM" "{\"assigneeId\":\"$JORDANID\"}"
hit "set in_progress" PATCH "/tickets/$NEWID" 200 "$SAM" '{"status":"in_progress"}'
hit "raise priority" PATCH "/tickets/$NEWID" 200 "$SAM" '{"priority":"urgent"}'
hit "empty update" PATCH "/tickets/$NEWID" 400 "$SAM" '{}'
hit "reply as requester" POST "/tickets/$NEWID/comments" 201 "$JORDAN" '{"body":"Any update on this?"}'
hit "reply as moderator" POST "/tickets/$NEWID/comments" 201 "$SAM" '{"body":"Looking into it now."}'
hit "resolve" PATCH "/tickets/$NEWID" 200 "$SAM" '{"status":"resolved"}'
hit "close" PATCH "/tickets/$NEWID" 200 "$SAM" '{"status":"closed"}'
hit "reopen a closed ticket" PATCH "/tickets/$NEWID" 409 "$SAM" '{"status":"open"}'
hit "reply to a closed ticket" POST "/tickets/$NEWID/comments" 409 "$SAM" '{"body":"late"}'
hit "delete as moderator" DELETE "/tickets/$NEWID" 403 "$SAM"
hit "delete as admin" DELETE "/tickets/$NEWID" 204 "$ADMIN"
hit "deleted ticket is gone" GET "/tickets/$NEWID" 404 "$SAM"

echo
echo "== SCOPING =="
OTHER=$(curl -s "$BASE/tickets?limit=100" -H "Authorization: Bearer $SAM" -H 'Accept: application/json' \
  | python3 -c "import sys,json;d=json.load(sys.stdin)['data'];print(next(t['id'] for t in d if t['requester']['id']!='$JORDANID'))")
hit "user reading another's ticket" GET "/tickets/$OTHER" 403 "$JORDAN"
hit "user replying to another's ticket" POST "/tickets/$OTHER/comments" 403 "$JORDAN" '{"body":"nope"}'

echo
echo "== CATEGORIES =="
hit "list as user" GET "/categories" 200 "$JORDAN"
hit "includeRetired as user" GET "/categories?includeRetired=1" 403 "$JORDAN"
hit "includeRetired as admin" GET "/categories?includeRetired=1" 200 "$ADMIN"
STAMP=$RANDOM
hit "create as moderator" POST "/categories" 403 "$SAM" '{"name":"Nope"}'
NEWCAT=$(curl -s -X POST "$BASE/categories" -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"name\":\"Smoke Category $STAMP\"}")
NEWCATID=$(echo "$NEWCAT" | jget 'data.id')
[ -n "$NEWCATID" ] && { PASS=$((PASS+1)); echo "  ok   201 create category ($(echo "$NEWCAT" | jget 'data.slug'))"; } || { FAIL=$((FAIL+1)); echo "  FAIL create category"; }
hit "rename" PATCH "/categories/$NEWCATID" 200 "$ADMIN" '{"name":"Smoke Renamed"}'
hit "retire" PATCH "/categories/$NEWCATID" 200 "$ADMIN" '{"isActive":false}'

echo
echo "== ACCOUNTS =="
hit "list as admin" GET "/users" 200 "$ADMIN"
hit "list as moderator" GET "/users" 403 "$SAM"
hit "filter by role" GET "/users?role=moderator" 200 "$ADMIN"
hit "search" GET "/users?search=jordan" 200 "$ADMIN"
hit "assignable as moderator" GET "/users/assignable" 200 "$SAM"
hit "assignable as user" GET "/users/assignable" 403 "$JORDAN"
USERROLE=$(curl -s "$BASE/users?role=user&limit=1" -H "Authorization: Bearer $ADMIN" -H 'Accept: application/json' >/dev/null; \
  cd ~/Desktop/helpdesk/helpdesk-api && php artisan tinker --execute "echo App\Models\Role::where('slug','user')->value('id');" 2>/dev/null | tail -1)
NEWUSER=$(curl -s -X POST "$BASE/users" -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"name\":\"Smoke Person\",\"email\":\"smoke$STAMP@example.com\",\"password\":\"Sufficient1Password\",\"roleId\":\"$USERROLE\"}")
NEWUSERID=$(echo "$NEWUSER" | jget 'data.id')
[ -n "$NEWUSERID" ] && { PASS=$((PASS+1)); echo "  ok   201 create account"; } || { FAIL=$((FAIL+1)); echo "  FAIL create account: $(head -c 200 <<<"$NEWUSER")"; }
hit "weak password" POST "/users" 400 "$ADMIN" "{\"name\":\"X\",\"email\":\"weak$STAMP@example.com\",\"password\":\"short\",\"roleId\":\"$USERROLE\"}"
hit "deactivate" PATCH "/users/$NEWUSERID" 200 "$ADMIN" '{"isActive":false}'
ADMINID=$(curl -s "$BASE/auth/me" -H "Authorization: Bearer $ADMIN" -H 'Accept: application/json' | jget 'data.user.id')
hit "admin deactivating themselves" PATCH "/users/$ADMINID" 409 "$ADMIN" '{"isActive":false}'
hit "view account" GET "/users/$NEWUSERID" 200 "$ADMIN"
hit "view account as moderator" GET "/users/$NEWUSERID" 403 "$SAM"
hit "reset link to deactivated account" POST "/users/$NEWUSERID/password-reset" 409 "$ADMIN"
hit "reactivate" PATCH "/users/$NEWUSERID" 200 "$ADMIN" '{"isActive":true}'
hit "send reset link" POST "/users/$NEWUSERID/password-reset" 202 "$ADMIN"
hit "delete account as moderator" DELETE "/users/$NEWUSERID" 403 "$SAM"
hit "delete account" DELETE "/users/$NEWUSERID" 204 "$ADMIN"
hit "deleted account is gone" GET "/users/$NEWUSERID" 404 "$ADMIN"
hit "admin deleting themselves" DELETE "/users/$ADMINID" 409 "$ADMIN"

echo
echo "== PASSWORD RESET =="
hit "forgot password, known address" POST "/auth/forgot-password" 202 "" '{"email":"jordan@example.com"}'
hit "forgot password, unknown address" POST "/auth/forgot-password" 202 "" '{"email":"nobody@example.com"}'
hit "forgot password, no email" POST "/auth/forgot-password" 400 "" '{}'
hit "reset with a bad link" POST "/auth/reset-password" 400 "" '{"token":"0000000000000000000000000000000000000000000000000000000000000000","email":"jordan@example.com","password":"BrandNew2Password","password_confirmation":"BrandNew2Password"}'

echo
echo "== METRICS =="
hit "as admin" GET "/metrics" 200 "$ADMIN"
hit "as moderator" GET "/metrics" 403 "$SAM"

echo
echo "== REFRESH ROTATION =="
cp "$JAR_DIR/jordan.jar" "$JAR_DIR/replay.jar"
hit "refresh rotates" POST "/auth/refresh" 200 "" "" "jordan.jar"
hit "replaying the old token" POST "/auth/refresh" 401 "" "" "replay.jar"
hit "family is burned" POST "/auth/refresh" 401 "" "" "jordan.jar"

echo
echo "== LOGOUT =="
hit "logout" POST "/auth/logout" 204 "" "" "sam.jar"
hit "logout again" POST "/auth/logout" 204 "" "" "sam.jar"
hit "logout everywhere" POST "/auth/logout-all" 204 "$ADMIN"
hit "token dead after logout-all" GET "/auth/me" 401 "$ADMIN"

echo
echo "======================================"
printf ' passed: %d   failed: %d\n' "$PASS" "$FAIL"
