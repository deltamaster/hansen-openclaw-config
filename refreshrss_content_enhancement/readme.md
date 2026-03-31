### Project Goal: FreshRSS Content Enhancement & Filtering Plugin

 **Context:** 
I am building a FreshRSS plugin to process incoming RSS entries (specifically summary-only feeds like Google News) before they are stored in the database.

 **Core Workflow:** 
1. **Hook:** Trigger the process using the `entry_before_insert` hook in FreshRSS.
2. **Full-text Extraction:** 
   - Detect if an entry has a redirect link (common in Google News).
   - Fetch the original webpage content from the source URL.
   - Use a library like `fivefilters/readability-php` (or similar logic) to extract the clean main article text, stripping navigation, ads, and footers.
3. **LLM Processing:** 
   - Send the extracted text to an LLM API (e.g., OpenAI, DeepSeek, or a local Ollama instance).
   - **Task for LLM:** 
     - Generate a concise summary (approx. 100-150 words in Chinese).
     - Assign a **relevance_score** (1–10): combines objective information value and subjective interest (tune via system prompt).
     - Detect specific categories: "Advertisement", "Propaganda", "Clickbait", "Low Quality".
     - **Label storage:** Freeform labels are normalized to lowercase ASCII letters only (spaces/punctuation stripped). Internal tags `_lowquality`, `_highquality`, `_propaganda`, `_ads`, `_antirobots` are kept verbatim. The extension adds `_lowquality` when `relevance_score` ≤ 3 and `_highquality` when ≥ 9.
   - **Response Format:** Expect a structured JSON response.
4. **Data Enrichment:** 
   - Replace the original entry summary/content with the LLM-generated summary.
   - Store metadata (`relevance_score`, tags) in the `$entry->attributes()` array.
   - If **relevance_score** is below a certain threshold (configurable), mark the entry as read or discard it.

 **Technical Requirements:** 
- **Language:** PHP (FreshRSS standard).
- **Configuration UI:** The plugin should have a settings page in the FreshRSS admin panel to configure:
  - LLM API Endpoint & API Key.
  - Model Name (e.g., `gpt-4o`, `deepseek-chat`).
  - Minimum relevance score threshold for filtering.
  - Scoring criteria (shared `relevance_score` rubric) plus separate structure prompts for prefilter vs full scan.
- **Error Handling:** Graceful degradation if the API is down or the scraper fails (keep the original entry in such cases).
- **Logging:** Log API latency and extraction status for debugging purposes.

 **Implementation Structure:** 
- `extension.php`: Main entry point containing the hook registration.
- `metadata.json`: Plugin metadata.
- `configure.phtml`: Settings UI.
- `Processor.php`: (Optional) Helper class for Scraper and LLM API calls.

---

### Generated boilerplate (this repo)

The extension skeleton lives under **`xExtension-ContentEnhancement/`**:

| File | Role |
|------|------|
| `metadata.json` | Extension identity (`type: system`, admin-wide settings). |
| `extension.php` | Registers `Minz_HookType::EntryBeforeInsert`, install defaults, configure handler. |
| `Processor.php` | URL resolve + fetch + stub text extraction + OpenAI-compatible `/chat/completions` JSON + `_attribute()` metadata. |
| `configure.phtml` | Admin form: API, thresholds, shared **scoring criteria** (prefilter/full structure prompts are fixed English strings in `Processor.php`). |

**Install:** copy `xExtension-ContentEnhancement` into your FreshRSS tree as `FreshRSS/extensions/xExtension-ContentEnhancement`, then enable **ContentEnhancement** under Administration → Extensions → System, open **configuration**, set API base/key/model, enable.

**Dependencies:** PHP `curl` extension. Optional: add `fivefilters/readability-php` (Composer) and call it from `ContentEnhancement_Processor::extractReadableText()` for real article extraction. Some local backends (e.g. Ollama) may not support `response_format: json_object`; remove that field in `callOpenAiCompatibleChat()` if the server rejects it.

**FreshRSS extension docs:** [Writing extensions for FreshRSS](https://freshrss.github.io/FreshRSS/en/developers/03_Backend/05_Extensions.html).

---

### Local testing (Windows)

**Recommended: Docker** — PHP, Apache, and FreshRSS run inside the official image; your extension folder is bind-mounted so edits apply immediately (restart not usually required for PHP file changes).

**GFW / egress proxy:** Container outbound traffic is configured to use a **SOCKS5 proxy on the host at port 1080** (e.g. `ssh -D 1080`). Start that tunnel first, then Docker — see **[LOCAL-GFW-BYPASS.md](./LOCAL-GFW-BYPASS.md)** for the full checklist and troubleshooting.

1. Install [Docker Desktop](https://docs.docker.com/desktop/setup/install/windows-install/) and ensure it is running.
2. From this directory run:

   ```powershell
   .\run-local.ps1
   ```

   Or manually: `docker compose pull` then `docker compose up -d`.

   The compose file uses **`dockerproxy.net/freshrss/freshrss:latest`** (Docker Hub mirror) by default so pulls do not hit `registry-1.docker.io` directly. To use Docker Hub instead, set `image: freshrss/freshrss:latest` in `docker-compose.yml`.

3. Open [http://127.0.0.1:8081/](http://127.0.0.1:8081/), run the web installer once (use **SQLite** for a quick dev DB), create an admin user. (Port **8081** avoids clashing with an SSH tunnel on **8080** to a remote FreshRSS.)
4. Enable the extension: **Administration → System configuration → System extensions** → enable **ContentEnhancement** → **configuration** (set API base, key, model).
5. Stop the stack: `docker compose down`. Data is kept in the Docker volume `freshrss_content_enhancement_freshrss_data` until you remove it with `docker compose down -v`.

If you **override** the image to `freshrss/freshrss:latest` and pulls fail: timeout to `registry-1.docker.io`, wrong DNS, or TLS errors — use the default **`dockerproxy.net/...`** image again, or fix DNS / use a [registry mirror](https://docs.docker.com/docker-hub/mirror/).

**Optional: PHP on the host** — useful only for `php -l` / IDE tooling on extension files (not required to run FreshRSS if you use Docker):

```powershell
winget install --id PHP.PHP.8.3 -e --accept-source-agreements --accept-package-agreements
```

After install, add PHP to your PATH if the installer does not (see winget output).

The shared **`config/freshrss/docker-compose.yml`** at repo root is the same image pattern for servers; this folder’s compose is tailored for **local plugin development** with the extension directory mounted.