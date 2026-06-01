<?php
/**
 * CAPTCHAManager - Support for hCaptcha (privacy-friendly CAPTCHA)
 * Free tier available, no API key required for testing
 */

class CAPTCHAManager {
    const CAPTCHA_ENABLED = true;
    const CAPTCHA_TYPE = 'hcaptcha'; // or 'turnstile' or 'recaptcha'
    const VERIFICATION_URL = 'https://hcaptcha.com/siteverify';
    const TIMEOUT = 5;

    /**
     * Get HTML for CAPTCHA widget
     * @return string HTML to embed in form
     */
    public static function getHTML() {
        if (!self::CAPTCHA_ENABLED) {
            return '';
        }

        $siteKey = getenv('CAPTCHA_SITE_KEY') ?: 'dummy_site_key_for_testing';

        return <<<HTML
<div class="h-captcha" data-sitekey="$siteKey" data-theme="dark"></div>
<script src="https://js.hcaptcha.com?render=explicit" async defer></script>
HTML;
    }

    /**
     * Verify CAPTCHA response
     * @param string $response Response token from CAPTCHA widget
     * @param string $remoteIP Client IP address
     * @return array ['success' => bool, 'score' => float, 'message' => string]
     */
    public static function verify($response, $remoteIP = null) {
        if (!self::CAPTCHA_ENABLED) {
            return ['success' => true, 'score' => 1.0, 'message' => 'CAPTCHA disabled'];
        }

        if (!$response) {
            return ['success' => false, 'score' => 0, 'message' => 'CAPTCHA response missing'];
        }

        $secret = getenv('CAPTCHA_SECRET') ?: 'dummy_secret_for_testing';
        $remoteIP = $remoteIP ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        // Prepare verification request
        $postData = http_build_query([
            'response' => $response,
            'secret' => $secret,
            'remoteip' => $remoteIP
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $postData,
                'timeout' => self::TIMEOUT
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]);

        $result = @file_get_contents(self::VERIFICATION_URL, false, $context);

        if (!$result) {
            error_log('CAPTCHA verification failed - service unavailable');
            // Fail open during service outage
            return ['success' => true, 'score' => 1.0, 'message' => 'CAPTCHA service unavailable'];
        }

        $data = json_decode($result, true);

        if (!isset($data['success'])) {
            return ['success' => false, 'score' => 0, 'message' => 'Invalid CAPTCHA response'];
        }

        return [
            'success' => (bool)$data['success'],
            'score' => (float)($data['score'] ?? ($data['success'] ? 1.0 : 0.0)),
            'challenge_ts' => $data['challenge_ts'] ?? null,
            'hostname' => $data['hostname'] ?? null,
            'message' => $data['success'] ? 'CAPTCHA verified' : 'CAPTCHA failed'
        ];
    }

    /**
     * Should require CAPTCHA based on failed attempts
     * @param int $failedAttempts Number of failed login attempts
     * @return bool
     */
    public static function shouldRequire($failedAttempts) {
        return $failedAttempts >= 3; // After 3 failed attempts
    }

    /**
     * Get CAPTCHA HTML with conditional rendering
     * @param bool $required Whether CAPTCHA should be shown
     * @return string HTML
     */
    public static function getConditionalHTML($required) {
        if (!$required) {
            return '';
        }

        return '<div id="captcha-container" style="margin: 15px 0;">' . self::getHTML() . '</div>';
    }
}
?>
