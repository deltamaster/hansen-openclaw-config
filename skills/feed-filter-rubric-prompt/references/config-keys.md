# Form field names (POST body)

The **feed-filter-rubric-prompt** skill is about editing **`system_prompt`**; the POST still includes every field the form expects (from **get** output).

Same as **`configure.phtml`** / **`handleConfigureAction()`**. The Python **`contentenhancement_config_post.py`** builds the body like the browser for checkboxes (hidden `0` plus `1` when checked). It **does not** add **`slider`** unless **`FRESHRSS_POST_SLIDER=1`** — the captured browser payload in [`example_post_request`](../example_post_request) includes `slider=1`; that is normal for UI submits but **omit** `slider` for direct script POSTs unless you intentionally enable it.

For raw **`curl`**, use **`--data-urlencode`** per [pipeline.md](pipeline.md). To change only some fields, still send a full consistent set (copy from get JSON).

| Key | Notes |
|-----|--------|
| `_csrf` | Required every POST. Must come from the **configure** form for Content Enhancement (get script parses the correct one). |
| `system_prompt` | Full rubric text. Server-side `normalizeScoringCriteria()` runs on save (same as UI). |
| `min_quality` | Integer 1–10. Prefilter uses scores **strictly below** `min_quality − 1` for skip/drop paths. |
| `enabled` | JSON boolean; POST uses `enabled=0` or `enabled=0`+`enabled=1` when true. |
| `prefilter_before_fetch` | boolean |
| `mark_low_quality_read` | boolean |
| `discard_below_threshold` | boolean |
| `apply_freshrss_labels` | boolean |
| `api_base` | URL string |
| `api_key` | Empty string keeps existing key; do not log real values. |
| `model` | string |
| `slider` | **Only** if emulating full UI slider POST: set **`FRESHRSS_POST_SLIDER=1`** in the environment when using the Python script. Otherwise omit. |

The get script includes **`"slider": "1"`** in JSON for reference; post ignores it unless **`FRESHRSS_POST_SLIDER=1`**.
