# AppyReward for REDCap (External Module) – v0.1.0

## What it does

AppyReward for REDCap helps REDCap projects deliver participant incentives through AppyReward after a survey is completed.

The module supports two modes per survey instrument:

### 1) Anonymous surveys

For anonymous surveys, the module adds a reward button to the REDCap acknowledgement page (Thank You page).

The button points to:

```text
https://www.appyreward.com/campaign/redcapgifting/{CAMPAIGN_REF}
```

No participant email address is sent by REDCap in anonymous mode.

### 2) Identified surveys

For identified surveys, where an email field is collected in REDCap, the module sends a secure server-side webhook to AppyReward when the survey is completed.

The webhook endpoint is:

```text
https://www.appyreward.com/redcap/subscribe/{CAMPAIGN_REF}
```

Webhook requests are signed with HMAC SHA-256 and verified server-side by AppyReward.

## Data transmitted to AppyReward

When identified mode is enabled, the following data is transmitted to AppyReward:

- Participant email address from the configured REDCap email field
- REDCap project ID
- REDCap record ID
- REDCap event ID
- REDCap instrument name
- REDCap response ID
- REDCap repeat instance
- UTC timestamp

No data is sent unless:

1. the module is enabled for the REDCap project,
2. an instrument is configured,
3. identified mode is selected for that instrument,
4. an email field is configured,
5. the survey completion hook is triggered.

## Privacy and consent

Project administrators are responsible for ensuring that participants are properly informed when incentives are delivered through AppyReward.

For identified surveys, the configured participant email field is sent to AppyReward for the purpose of delivering the incentive.

For anonymous surveys, REDCap does not send the participant email address to AppyReward through the module. Instead, the participant is shown a universal reward link on the acknowledgement page.

AppyReward recommends that study teams clearly describe the incentive process in their consent language, survey introduction, or participant-facing documentation where applicable.

## Features

- Supports anonymous and identified surveys
- Secure server-side webhook delivery
- HMAC-signed requests using SHA-256
- Built-in deduplication to reduce duplicate incentives
- Per-instrument configuration
- No direct SQL queries
- No JavaScript dependency
- No modification of existing survey logic

## Installation

1. Copy the folder `appyreward_redcap_v0.1.0/` into `<REDCAP_ROOT>/modules/`
2. In REDCap Control Center → External Modules, enable the module at system level
3. In the REDCap project → External Modules, enable the module at project level
4. Configure the AppyReward HMAC secret and instrument settings

## Configuration

### Global project setting

- **AppyReward HMAC Secret**: shared secret used to sign webhook requests sent to AppyReward

### Per-instrument settings

- **Instrument name**: REDCap instrument variable name
- **AppyReward campaign reference**: campaign reference from AppyReward
- **Survey mode**:
  - Anonymous: show reward link on the acknowledgement page
  - Identified: send webhook on survey completion
- **Anonymous link label**: optional button label, defaults to `Get your gift`
- **Email field variable**: required only for identified mode

## Recommendations

- For identified surveys, use a REDCap Text field with email validation enabled.
- Make the email field required if incentive delivery is mandatory.
- Use one AppyReward campaign reference per incentive workflow.
- Test each configured instrument before using the module in production.
- Ensure outbound HTTPS requests are allowed from the REDCap server to `www.appyreward.com`.

## Requirements

- REDCap 10.2.0 or higher with External Modules enabled
- PHP 5.4 or higher
- cURL enabled
- Outbound HTTPS access to `www.appyreward.com`

## Security notes

- Webhook requests are signed using HMAC SHA-256.
- The HMAC signature is generated from the timestamp and raw JSON body.
- SSL peer and host verification are enabled for outbound HTTPS requests.
- The module avoids logging participant email addresses and webhook response bodies.
- Deduplication keys are stored as project settings and bounded to reduce unbounded growth.

## Limitations

- The built-in deduplication mechanism is designed for a simple first release.
- High-volume REDCap projects may require a more scalable deduplication storage strategy.
- The module does not create or manage AppyReward campaigns from inside REDCap.

## Support

**AppyReward Support**  
Email: helpdesk@appyreward.com  
Website: https://www.appyreward.com
