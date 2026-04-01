---
name: email-me
description: "Send rendered markdown content as an email via AWS SES using boto3. Trigger when user wants to email themselves a markdown file. Hardcoded recipient: huhansen318@hotmail.com."
metadata: {"openclaw": {"emoji": "📧", "primaryEnv": "AWS_ACCESS_KEY_ID"}}
---

# email-me Skill

Send a markdown file as a beautifully rendered HTML email via AWS SES.

## Setup

Enter both AWS credentials in the **`apiKey`** field (shown in Control UI), separated by a comma:

```
AKIAXXXXXXXXXXXXXXXXXX,xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Format: `<access_key_id>,<secret_access_key>`

## Usage

When user says "send me X.md as email" or similar:

1. Read `apiKey` from `openclaw.json` → `skills.entries.email-me`
2. Parse the comma-separated credentials: `access_key_id,secret_access_key`
3. Read the markdown file at the provided path
4. Render markdown to HTML
5. Call `scripts/send_email.py` with credentials and file path

```bash
python3 ~/.openclaw/workspace/skills/email-me/scripts/send_email.py \
  --access-key-id "AKIAXXXX" \
  --secret-access-key "xxxxx" \
  --markdown-file /path/to/file.md \
  --subject "My Subject"
```

## Script Behavior

- **Hardcoded recipient:** huhansen318@hotmail.com (cannot be changed)
- **Sender:** Must be a verified SES identity (or domain)
- **Region:** us-east-1 (default SES region)
- Renders markdown to HTML with proper styling for email clients
- Handles errors gracefully and reports success/failure

## Requirements

```bash
pip install boto3 markdown
```
