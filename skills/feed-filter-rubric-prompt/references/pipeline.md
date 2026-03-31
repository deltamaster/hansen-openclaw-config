# Pipeline (detail)

## Configure URL

`http://localhost:8080/i/?c=extension&a=configure&e=ContentEnhancement`  
(Override host and port with **`FRESHRSS_BASE`**; point it at your FreshRSS instance.)

**`e=`** = extension **name** in `metadata.json` (`ContentEnhancement`). Same mechanism as [Writing extensions for FreshRSS](https://freshrss.github.io/FreshRSS/en/developers/03_Backend/05_Extensions.html).

---

## Flow

1. **Run get** — fetch HTML, parse **`_csrf`** and all fields; print JSON on stdout. With **`FRESHRSS_COOKIE` unset**, the script saves session cookies to **`freshrss_configure.cookies`** (or **`FRESHRSS_COOKIE_FILE`**).
2. **Edit** — for the **feed-filter-rubric-prompt** skill, change **`system_prompt`** (the rubric); keep other keys as returned unless you intend to change them. Leave **`api_key`** empty unless you intend to replace the key.
3. **Run post** — pipe that JSON to stdin, still with **`FRESHRSS_COOKIE` unset** so the script loads the same cookie file. The body matches the form: **`_csrf`**, checkbox pairs, text fields. It does **not** send **`slider`** unless **`FRESHRSS_POST_SLIDER=1`** (the browser capture in [`example_post_request`](../example_post_request) may include `slider=1`; omit it for these scripts unless you know you need it).

**Environment:** optional **`FRESHRSS_BASE`** (default `http://localhost:8080`), optional **`FRESHRSS_EXTENSION`** if the folder name differs from `ContentEnhancement`.

**Session:** leave **`FRESHRSS_COOKIE`** unset. **get** writes **`freshrss_configure.cookies`** next to the scripts; **post** reads it.

```bash
export FRESHRSS_BASE='http://127.0.0.1:8080'   # or your FreshRSS URL (tunnel / server port)
# Repo clone: cd skills/feed-filter-rubric-prompt
# OpenClaw gateway: cd ~/.openclaw/workspace/skills/feed-filter-rubric-prompt
unset FRESHRSS_COOKIE   # bash — Windows cmd: set FRESHRSS_COOKIE=
python3 scripts/contentenhancement_config_get.py > ce-config.json 2>get.log
# edit ce-config.json
python3 scripts/contentenhancement_config_post.py < ce-config.json
# stderr: OK (HTTP 302 redirect after save) on success
```

Scripts: [`scripts/contentenhancement_config_get.py`](../scripts/contentenhancement_config_get.py), [`scripts/contentenhancement_config_post.py`](../scripts/contentenhancement_config_post.py).

---

## Troubleshooting

| Symptom | Likely cause |
|--------|----------------|
| Redirect to **`c=error`**, 403 text mentions CSRF | Stale **`_csrf`** vs cookie file — always run **get** and post the new JSON in the same session without deleting the cookie file between them. |
| Login HTML from get | No valid session yet — open FreshRSS in the browser, sign in as an admin, then run **get** again so the cookie file receives the session cookie. |
| Post “404” or wrong page after following redirects | Do not follow redirects on error URLs. The post script does not follow redirects by design; a **302** to a non-error **`Location`** counts as success. |

---

## Raw curl (optional)

**One GET** for HTML / CSRF, **one POST** with `application/x-www-form-urlencoded` — see [`example_get_request`](../example_get_request) and [`example_post_request`](../example_post_request). Prefer the Python pair for a correct body without **`slider`**.

Field semantics: [config-keys.md](config-keys.md).
