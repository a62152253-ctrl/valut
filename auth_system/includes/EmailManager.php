<?php
/**
 * EmailManager - SMTP-based email sending with retry logic
 * Uses PHPMailer for reliable email delivery (requires composer install)
 */

// PHPMailer must be loaded before the class body
$_emailAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($_emailAutoload)) {
    require_once $_emailAutoload;
    define('PHPMAILER_AVAILABLE', true);
} else {
    define('PHPMAILER_AVAILABLE', false);
    error_log('EmailManager: PHPMailer not installed. Run: composer require phpmailer/phpmailer');
}
unset($_emailAutoload);

if (PHPMAILER_AVAILABLE) {
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;
}

class EmailManager {
    private static $mailer = null;

    /**
     * Initialize PHPMailer with SMTP configuration from environment
     */
    public static function init() {
        if (self::$mailer !== null) return;
        if (!PHPMAILER_AVAILABLE) return;

        self::$mailer = new PHPMailer(true);
        self::$mailer->isSMTP();
        self::$mailer->Host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        self::$mailer->Port = (int)(getenv('SMTP_PORT') ?: 587);
        self::$mailer->SMTPAuth = true;
        self::$mailer->Username = getenv('SMTP_USER') ?: '';
        self::$mailer->Password = getenv('SMTP_PASS') ?: '';
        self::$mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        self::$mailer->setFrom(
            getenv('MAIL_FROM') ?: 'noreply@vaultly.local',
            getenv('MAIL_FROM_NAME') ?: 'Vaultly Vault'
        );
        self::$mailer->isHTML(true);
    }

    /**
     * Send password reset email
     * @param string $email User email
     * @param string $token Reset token
     * @param string $username User's username
     * @return bool Success
     */
    public static function sendPasswordReset($email, $token, $username) {
        self::init();
        if (!self::$mailer) return false;
        try {
            $resetLink = rtrim(getenv('APP_URL') ?: 'http://localhost', '/') . 
                         "/reset_password.php?token=" . urlencode($token);

            $html = <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: white; margin: 0;">🔐 Password Reset Request</h1>
    </div>
    <div style="background: #f5f5f5; padding: 30px; border-radius: 0 0 8px 8px;">
        <p>Hi <strong>$username</strong>,</p>
        <p>We received a request to reset your Vaultly password. Click the link below to create a new password:</p>
        <div style="text-align: center; margin: 30px 0;">
            <a href="$resetLink" style="background: #667eea; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">
                Reset Password
            </a>
        </div>
        <p style="color: #666; font-size: 12px;">Or copy this link: <br><span style="word-break: break-all;">$resetLink</span></p>
        <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
        <p style="color: #999; font-size: 12px;">
            This link expires in <strong>1 hour</strong>.<br>
            If you didn't request this, please ignore this email.<br>
            Your account security is our priority. Never share this link.
        </p>
    </div>
</div>
HTML;

            self::$mailer->addAddress($email);
            self::$mailer->Subject = 'Password Reset Request - Vaultly';
            self::$mailer->Body = $html;
            self::$mailer->AltBody = "Reset your password: $resetLink\n\nThis link expires in 1 hour.";

            return self::$mailer->send();
        } catch (Exception $e) {
            error_log("Email sending failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send email verification link
     * @param string $email User email
     * @param string $verificationToken Verification token
     * @param string $username User's username
     * @return bool Success
     */
    public static function sendEmailVerification($email, $verificationToken, $username) {
        self::init();
        try {
            $verifyLink = rtrim(getenv('APP_URL') ?: 'http://localhost', '/') . 
                          "/verify_email.php?token=" . urlencode($verificationToken);

            $html = <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: white; margin: 0;">✅ Verify Your Email</h1>
    </div>
    <div style="background: #f5f5f5; padding: 30px; border-radius: 0 0 8px 8px;">
        <p>Welcome to Vaultly, <strong>$username</strong>!</p>
        <p>Please verify your email address to complete your registration:</p>
        <div style="text-align: center; margin: 30px 0;">
            <a href="$verifyLink" style="background: #22c55e; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">
                Verify Email
            </a>
        </div>
        <p style="color: #666; font-size: 12px;">Or copy this link: <br><span style="word-break: break-all;">$verifyLink</span></p>
        <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
        <p style="color: #999; font-size: 12px;">
            This link expires in <strong>24 hours</strong>.<br>
            If you didn't create this account, please ignore this email.
        </p>
    </div>
</div>
HTML;

            self::$mailer->clearAddresses();
            self::$mailer->addAddress($email);
            self::$mailer->Subject = 'Verify Your Email - Vaultly';
            self::$mailer->Body = $html;
            self::$mailer->AltBody = "Verify your email: $verifyLink\n\nThis link expires in 24 hours.";

            return self::$mailer->send();
        } catch (Exception $e) {
            error_log("Email verification sending failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send security alert for unusual activity
     * @param string $email User email
     * @param array $alert Alert details
     * @return bool Success
     */
    public static function sendSecurityAlert($email, $alert) {
        self::init();
        try {
            $alertTitle = $alert['type'] ?? 'Security Alert';
            $alertMessage = $alert['message'] ?? 'Unusual activity detected on your account.';
            $alertTime = date('M j, Y H:i:s', $alert['timestamp'] ?? time());
            $alertIP = $alert['ip'] ?? 'Unknown';

            $html = <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: linear-gradient(135deg, #ef5350 0%, #e53935 100%); padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: white; margin: 0;">⚠️ $alertTitle</h1>
    </div>
    <div style="background: #f5f5f5; padding: 30px; border-radius: 0 0 8px 8px;">
        <p><strong>$alertMessage</strong></p>
        <div style="background: #fff; padding: 15px; border-radius: 5px; border-left: 4px solid #ef5350; margin: 20px 0;">
            <p style="margin: 5px 0;"><strong>Time:</strong> $alertTime</p>
            <p style="margin: 5px 0;"><strong>IP Address:</strong> $alertIP</p>
        </div>
        <p>If this wasn't you, <a href="https://vaultly.local/actions/settings.php" style="color: #667eea;">change your password immediately</a> and enable two-factor authentication.</p>
        <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
        <p style="color: #999; font-size: 12px;">Never share this email or click links from untrusted sources.</p>
    </div>
</div>
HTML;

            self::$mailer->clearAddresses();
            self::$mailer->addAddress($email);
            self::$mailer->Subject = '⚠️ ' . $alertTitle . ' - Vaultly';
            self::$mailer->Body = $html;
            self::$mailer->AltBody = "$alertTitle\n\n$alertMessage\n\nTime: $alertTime\nIP: $alertIP";

            return self::$mailer->send();
        } catch (Exception $e) {
            error_log("Security alert sending failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Test SMTP connection
     * @return array [success => bool, message => string]
     */
    public static function testConnection() {
        self::init();
        try {
            self::$mailer->smtpConnect();
            self::$mailer->smtpClose();
            return ['success' => true, 'message' => 'SMTP connection successful'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'SMTP connection failed: ' . $e->getMessage()];
        }
    }
}

?>
