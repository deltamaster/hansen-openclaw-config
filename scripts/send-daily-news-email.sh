#!/usr/bin/env bash
# Send a markdown file as HTML email via Microsoft Graph (same auth as Outlook skill).
# Usage: send-daily-news-email.sh <to@email> <subject> <path-to-markdown-file>
set -euo pipefail

TO="${1:?recipient}"
SUBJECT="${2:?subject}"
FILE="${3:?body file}"

[[ -f "$FILE" ]] || { echo "Not a file: $FILE" >&2; exit 1; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HTML_TMP="$(mktemp)"
trap 'rm -f "${HTML_TMP}"' EXIT

python3 "${SCRIPT_DIR}/md_to_email_html.py" "${FILE}" >"${HTML_TMP}"

CONFIG_DIR="${HOME}/.outlook-mcp"
CREDS_FILE="${CONFIG_DIR}/credentials.json"
ACCESS_TOKEN=$(jq -r '.access_token' "$CREDS_FILE" 2>/dev/null)

if [[ -z "${ACCESS_TOKEN}" || "${ACCESS_TOKEN}" == "null" ]]; then
  echo "Error: No Outlook access token. Run outlook-setup.sh or refresh token." >&2
  exit 1
fi

API="https://graph.microsoft.com/v1.0/me/sendMail"
PAYLOAD=$(jq -n \
  --arg to "$TO" \
  --arg sub "$SUBJECT" \
  --rawfile body "$HTML_TMP" \
  '{message:{subject:$sub,body:{contentType:"HTML",content:$body},toRecipients:[{emailAddress:{address:$to}}]}}')

RESULT=$(curl -s -w "\n%{http_code}" -X POST "$API" \
  -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD")

HTTP_CODE=$(echo "$RESULT" | tail -n1)
BODY_OUT=$(echo "$RESULT" | head -n -1)

if [[ "$HTTP_CODE" == "202" ]]; then
  echo "{\"status\":\"sent\",\"to\":\"${TO}\",\"subject\":\"${SUBJECT}\",\"format\":\"html\"}"
else
  echo "$BODY_OUT" | jq '.error // .' 2>/dev/null || echo "$BODY_OUT"
  exit 1
fi
