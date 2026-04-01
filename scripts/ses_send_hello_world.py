"""
Send a hello-world email via Amazon SES using boto3 (AWS SDK for Python).
Prerequisites match https://docs.aws.amazon.com/ses/latest/dg/send-an-email-using-sdk-programmatically.html
"""

import os
import sys

import boto3
from botocore.exceptions import ClientError

# From must match the verified identity allowed by IAM (see identity/softrank.net@gmail.com).
# Sandbox: To must also be verified in SES.
SENDER = "softrank.net@gmail.com"
RECIPIENT = "huhansen318@hotmail.com"
AWS_REGION = "ap-northeast-1"
PROFILE = "deliveryman"

SUBJECT = "Hello world (Amazon SES / boto3)"
BODY_TEXT = (
    "Hello world\r\n"
    "This email was sent with Amazon SES using the AWS SDK for Python (Boto3)."
)
BODY_HTML = """<html>
<head></head>
<body>
  <h1>Hello world</h1>
  <p>This email was sent with
    <a href="https://aws.amazon.com/ses/">Amazon SES</a> using the
    <a href="https://aws.amazon.com/sdk-for-python/">AWS SDK for Python (Boto3)</a>.</p>
</body>
</html>"""
CHARSET = "UTF-8"


def main() -> int:
    session = boto3.Session(profile_name=PROFILE)
    client = session.client("ses", region_name=AWS_REGION)

    try:
        response = client.send_email(
            Destination={"ToAddresses": [RECIPIENT]},
            Message={
                "Body": {
                    "Html": {"Charset": CHARSET, "Data": BODY_HTML},
                    "Text": {"Charset": CHARSET, "Data": BODY_TEXT},
                },
                "Subject": {"Charset": CHARSET, "Data": SUBJECT},
            },
            Source=SENDER,
        )
    except ClientError as e:
        print(e.response["Error"]["Message"], file=sys.stderr)
        return 1
    else:
        print("Email sent! Message ID:", response["MessageId"])
        return 0


if __name__ == "__main__":
    if os.environ.get("AWS_PROFILE") and os.environ["AWS_PROFILE"] != PROFILE:
        print(
            f"Note: AWS_PROFILE={os.environ['AWS_PROFILE']!r} is set; "
            f"boto3 Session still uses profile {PROFILE!r}.",
            file=sys.stderr,
        )
    raise SystemExit(main())
