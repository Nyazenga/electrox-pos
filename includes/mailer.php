<?php
// Prevent direct access
if (!defined('APP_PATH')) {
    exit('No direct script access allowed');
}

require_once APP_PATH . '/config.php';
require_once APP_PATH . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer {
    private $mail;
    
    public function __construct() {
        $this->mail = new PHPMailer(true);
        $this->configure();
    }
    
    private function configure() {
        try {
            // Get SMTP settings from database or use defaults
            $smtpHost = getSetting('smtp_host', 'smtp.gmail.com');
            $smtpUsername = getSetting('smtp_username', 'nyazengamd@gmail.com');
            $smtpPassword = getSetting('smtp_password', 'vuamghodglqyvuxp');
            $smtpPort = getSetting('smtp_port', '587');
            $smtpFromEmail = getSetting('smtp_from_email', 'nyazengamd@gmail.com');
            $smtpFromName = getSetting('smtp_from_name', 'ELECTROX POS');
            
            $this->mail->isSMTP();
            $this->mail->Host = $smtpHost;
            $this->mail->SMTPAuth = true;
            $this->mail->Username = $smtpUsername;
            $this->mail->Password = $smtpPassword;
            $this->mail->Port = (int)$smtpPort;
            $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $this->mail->SMTPAutoTLS = true;
            
            // Fix SSL certificate issues
            $this->mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];
            
            $this->mail->setFrom($smtpFromEmail, $smtpFromName);
            $this->mail->CharSet = 'UTF-8';
        } catch (Exception $e) {
            logError("Mailer configuration error: " . $e->getMessage());
        }
    }
    
    public function send($to, $subject, $body, $isHtml = true) {
        try {
            $this->mail->clearAddresses();
            $this->mail->clearAttachments();
            // Don't clear embedded images - they may have been added before send() is called
            
            if (is_array($to)) {
                foreach ($to as $email) {
                    $this->mail->addAddress($email);
                }
            } else {
                $this->mail->addAddress($to);
            }
            
            $this->mail->isHTML($isHtml);
            $this->mail->Subject = $subject;
            $this->mail->Body = $body;
            
            if (!$isHtml) {
                $this->mail->AltBody = strip_tags($body);
            }
            
            return $this->mail->send();
        } catch (Exception $e) {
            logError("Mailer send error: " . $this->mail->ErrorInfo);
            return false;
        }
    }
    
    public function getMailer() {
        return $this->mail;
    }
    
    public function sendWithAttachment($to, $subject, $body, $attachmentPath, $isHtml = true) {
        try {
            if (file_exists($attachmentPath)) {
                $this->mail->addAttachment($attachmentPath);
            }
            return $this->send($to, $subject, $body, $isHtml);
        } catch (Exception $e) {
            logError("Mailer send with attachment error: " . $e->getMessage());
            return false;
        }
    }
}

