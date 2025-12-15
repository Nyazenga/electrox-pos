<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

header('Content-Type: application/json');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['receipt_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    $db = Database::getInstance();
    $sale = $db->getRow("SELECT * FROM sales WHERE id = :id", [':id' => $input['receipt_id']]);
    
    if (!$sale) {
        throw new Exception('Receipt not found');
    }
    
    $receiptUrl = BASE_URL . 'modules/pos/receipt.php?id=' . $input['receipt_id'];
    
    $sent = false;
    $message = '';
    
    // Send email
    if (!empty($input['email'])) {
        require_once APP_PATH . '/includes/mailer.php';
        $mailer = new Mailer();
        
        $subject = 'Receipt #' . $sale['receipt_number'] . ' - ' . SYSTEM_NAME;
        $body = '<h2>Thank you for your purchase!</h2>';
        $body .= '<p>Please find your receipt attached.</p>';
        $body .= '<p><a href="' . $receiptUrl . '">View Receipt Online</a></p>';
        
        if ($mailer->send($input['email'], $subject, $body)) {
            $sent = true;
            $message .= 'Email sent successfully. ';
        }
    }
    
    // Send WhatsApp (using WhatsApp Business API or Twilio)
    if (!empty($input['whatsapp'])) {
        // This would require WhatsApp Business API integration
        // For now, we'll just log it
        $message .= 'WhatsApp: ' . $input['whatsapp'] . ' (Integration required)';
    }
    
    echo json_encode([
        'success' => $sent || !empty($input['whatsapp']),
        'message' => $message ?: 'Receipt sent successfully'
    ]);
    
} catch (Exception $e) {
    logError("Send receipt error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to send receipt: ' . $e->getMessage()]);
}

