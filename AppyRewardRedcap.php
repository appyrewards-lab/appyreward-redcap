<?php
namespace AppyReward\Redcap;

use ExternalModules\AbstractExternalModule;

class AppyRewardRedcap extends AbstractExternalModule
{
    /**
     * Identified mode (reliable):
     * Trigger webhook server-side on survey completion.
     */
    public function redcap_survey_complete($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        $cfg = $this->getInstrumentConfig($instrument);
        if (!$cfg) { return; }

        if ((string)$cfg['mode'] !== 'identified') { return; }

        $campaignRef = trim((string)$cfg['campaign_ref']);
        if ($campaignRef === '') { return; }

        $emailField = trim((string)($cfg['email_field'] ?? ''));
        if ($emailField === '') {
            $this->emLog("Identified mode: missing email_field for instrument=$instrument");
            return;
        }

        $email = $this->getFieldValue($project_id, $record, $event_id, $emailField);
        $email = trim((string)$email);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->emLog("Identified mode: invalid or missing email value");
            return;
        }

        // Anti-duplicate (refresh / double trigger / retries)
        $dedupeKey = $this->buildDedupeKey($project_id, $record, $event_id, $instrument, $repeat_instance, $campaignRef);
        if ($this->isAlreadyProcessed($dedupeKey)) {
            $this->emLog("Skipped duplicate subscribe call");
            return;
        }

        $payload = [
            'email' => $email,
            'redcap_project_id' => (int)$project_id,
            'record_id' => (string)$record,
            'event_id' => (string)$event_id,
            'instrument' => (string)$instrument,
            'response_id' => (string)$response_id,
            'repeat_instance' => (string)$repeat_instance,
            'timestamp_utc' => gmdate('c')
        ];

        $url = 'https://www.appyreward.com/redcap/subscribe/' . rawurlencode($campaignRef);
        $res = $this->postJson($url, $payload);

        if ($res['ok']) {
            $this->markProcessed($dedupeKey);
            $this->emLog("Subscribe webhook OK", [
                'status' => $res['statusCode']
            ]);
        } else {
            $this->emLog("Subscribe webhook FAILED", [
                'status' => $res['statusCode']
            ]);
        }
    }

    /**
     * Anonymous mode:
     * Inject universal link on the acknowledgement (thank-you) page.
     * Also shows a simple thank-you message for identified mode.
     */
    public function redcap_survey_acknowledgement_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        $cfg = $this->getInstrumentConfig($instrument);
        if (!$cfg) { return; }

        $campaignRef = trim((string)$cfg['campaign_ref']);
        if ($campaignRef === '') { return; }

        $mode = (string)$cfg['mode'];

        if ($mode === 'anonymous') {
            $label = trim((string)($cfg['button_label'] ?? ''));
            if ($label === '') { $label = 'Get your gift'; }

            $link = 'https://www.appyreward.com/campaign/redcapgifting/' . rawurlencode($campaignRef);

            $safeLink  = htmlspecialchars($link, ENT_QUOTES);
            $safeLabel = htmlspecialchars($label, ENT_QUOTES);

            print '
            <div style="margin-top:18px; padding:16px; border:1px solid #ddd; border-radius:8px;">
              <div style="font-size:16px; font-weight:600; margin-bottom:8px;">Thank you!</div>
              <div style="margin-bottom:12px;">Click below to access your reward.</div>
              <a href="'.$safeLink.'" target="_blank" rel="noopener noreferrer"
                 style="display:inline-block; padding:10px 14px; border-radius:6px; text-decoration:none; border:1px solid #333;">
                 '.$safeLabel.'
              </a>
            </div>';
            return;
        }

        if ($mode === 'identified') {
            print '
            <div style="margin-top:18px; padding:16px; border:1px solid #ddd; border-radius:8px;">
              <div style="font-size:16px; font-weight:600; margin-bottom:8px;">Thank you!</div>
              <div>Your incentive will be sent to you by email shortly.</div>
            </div>';
            return;
        }
    }

    // -----------------------------
    // Helpers
    // -----------------------------

    private function getInstrumentConfig($instrument)
    {
        $rows = $this->getProjectSetting('instrument_configs');
        if (!is_array($rows)) { return null; }

        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            if ((string)$row['instrument'] === (string)$instrument) {
                return $row;
            }
        }
        return null;
    }

    private function getFieldValue($project_id, $record, $event_id, $field)
    {
        $params = [
            'project_id' => $project_id,
            'return_format' => 'array',
            'records' => [$record],
            'fields' => [$field],
            'events' => [$event_id]
        ];

        $data = \REDCap::getData($params);
        if (!isset($data[$record][$event_id][$field])) {
            return '';
        }
        return $data[$record][$event_id][$field];
    }

    private function postJson($url, array $payload)
    {
        $secret = (string)$this->getProjectSetting('hmac_secret');
        if ($secret === '') {
            return ['ok' => false, 'statusCode' => 0, 'error' => 'Missing hmac_secret in module settings'];
        }

        $timestamp = time();

        // IMPORTANT: use raw JSON string for signing
        $jsonBody = json_encode($payload);
        if ($jsonBody === false) {
            return ['ok' => false, 'statusCode' => 0, 'error' => 'JSON encode failed'];
        }

        // Signature base string: "{timestamp}.{raw_body}"
        $baseString = $timestamp . '.' . $jsonBody;

        // HMAC SHA256 (hex)
        $signature = hash_hmac('sha256', $baseString, $secret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-AppyReward-Timestamp: ' . $timestamp,
                'X-AppyReward-Signature: ' . $signature
            ],
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'statusCode' => 0, 'error' => $err];
        }

        return [
            'ok' => ($code >= 200 && $code < 300),
            'statusCode' => $code,
            'body' => $body
        ];
    }


    private function buildDedupeKey($project_id, $record, $event_id, $instrument, $repeat_instance, $campaignRef)
    {
        return implode('|', [
            (string)$project_id,
            (string)$record,
            (string)$event_id,
            (string)$instrument,
            (string)$repeat_instance,
            (string)$campaignRef
        ]);
    }

    private function isAlreadyProcessed($dedupeKey)
    {
        $processed = $this->getProjectSetting('processed_keys');
        if (!is_array($processed)) { $processed = []; }
        return in_array($dedupeKey, $processed, true);
    }

    private function markProcessed($dedupeKey)
    {
        $processed = $this->getProjectSetting('processed_keys');
        if (!is_array($processed)) { $processed = []; }

        $processed[] = $dedupeKey;

        // Keep it bounded (MVP)
        if (count($processed) > 5000) {
            $processed = array_slice($processed, -4000);
        }

        $this->setProjectSetting('processed_keys', $processed);
    }
}