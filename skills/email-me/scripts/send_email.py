#!/usr/bin/env python3
"""
Send a markdown file as HTML email via AWS SES.
Credentials: access_key_id,secret_access_key (comma-separated in apiKey field)
Hardcoded recipient: huhansen318@hotmail.com
"""

import argparse
import os
import sys

try:
    import boto3
    from botocore.exceptions import ClientError
except ImportError:
    print("ERROR: boto3 not installed. Run: pip install boto3")
    sys.exit(1)

try:
    import markdown
except ImportError:
    print("ERROR: markdown not installed. Run: pip install markdown")
    sys.exit(1)


# Hardcoded recipient - cannot be changed
RECIPIENT = "huhansen318@hotmail.com"
SENDER = "softrank.net@gmail.com"
REGION = "ap-northeast-1"


def parse_credentials(api_key: str) -> tuple:
    """Parse comma-separated credentials: access_key_id,secret_access_key"""
    parts = api_key.split(",")
    if len(parts) != 2:
        raise ValueError("Credentials must be in format: access_key_id,secret_access_key")
    return parts[0].strip(), parts[1].strip()


def render_markdown_to_html(markdown_text: str) -> str:
    """Render markdown content to HTML with email-friendly styling."""
    html_body = markdown.markdown(
        markdown_text,
        extensions=['tables', 'fenced_code', 'codehilite', 'nl2br']
    )
    
    # Wrap with email-friendly HTML template
    html_template = f"""
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {{
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            color: #333;
        }}
        h1, h2, h3, h4, h5, h6 {{
            color: #2c3e50;
            margin-top: 1.5em;
            margin-bottom: 0.5em;
        }}
        h1 {{ font-size: 1.8em; border-bottom: 2px solid #3498db; padding-bottom: 0.3em; }}
        h2 {{ font-size: 1.5em; border-bottom: 1px solid #eee; padding-bottom: 0.2em; }}
        code {{
            background-color: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.9em;
        }}
        pre {{
            background-color: #f8f8f8;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            border-left: 4px solid #3498db;
        }}
        pre code {{
            background: none;
            padding: 0;
        }}
        blockquote {{
            border-left: 4px solid #3498db;
            margin: 1em 0;
            padding-left: 1em;
            color: #666;
        }}
        table {{
            border-collapse: collapse;
            width: 100%;
            margin: 1em 0;
        }}
        th, td {{
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }}
        th {{
            background-color: #3498db;
            color: white;
        }}
        tr:nth-child(even) {{
            background-color: #f9f9f9;
        }}
        a {{
            color: #3498db;
            text-decoration: none;
        }}
        a:hover {{
            text-decoration: underline;
        }}
    </style>
</head>
<body>
{html_body}
</body>
</html>
"""
    return html_template.strip()


def send_email(access_key_id: str, secret_access_key: str, subject: str, html_content: str) -> bool:
    """Send email via AWS SES."""
    client = boto3.client(
        'ses',
        region_name=REGION,
        aws_access_key_id=access_key_id,
        aws_secret_access_key=secret_access_key
    )
    
    try:
        response = client.send_email(
            Source=SENDER,
            Destination={
                'ToAddresses': [RECIPIENT]
            },
            Message={
                'Subject': {
                    'Data': subject,
                    'Charset': 'UTF-8'
                },
                'Body': {
                    'Html': {
                        'Data': html_content,
                        'Charset': 'UTF-8'
                    }
                }
            }
        )
        print(f"SUCCESS: Email sent! Message ID: {response['MessageId']}")
        return True
    except ClientError as e:
        print(f"ERROR: Failed to send email: {e.response['Error']['Message']}")
        return False


def main():
    parser = argparse.ArgumentParser(description='Send markdown file as HTML email via AWS SES')
    parser.add_argument('--access-key-id', required=True, help='AWS Access Key ID')
    parser.add_argument('--secret-access-key', required=True, help='AWS Secret Access Key')
    parser.add_argument('--markdown-file', required=True, help='Path to markdown file')
    parser.add_argument('--subject', default='Email from email-me skill', help='Email subject')
    
    args = parser.parse_args()
    
    # Validate markdown file exists
    if not os.path.exists(args.markdown_file):
        print(f"ERROR: Markdown file not found: {args.markdown_file}")
        sys.exit(1)
    
    # Read and render markdown
    print(f"Reading markdown file: {args.markdown_file}")
    with open(args.markdown_file, 'r', encoding='utf-8') as f:
        markdown_content = f.read()
    
    print("Rendering markdown to HTML...")
    html_content = render_markdown_to_html(markdown_content)
    
    # Send email
    print(f"Sending email to {RECIPIENT}...")
    success = send_email(args.access_key_id, args.secret_access_key, args.subject, html_content)
    
    sys.exit(0 if success else 1)


if __name__ == '__main__':
    main()
