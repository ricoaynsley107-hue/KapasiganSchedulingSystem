<?php
/**
 * Send Reminder Messages - Unified Reminder System
 * Handles email, SMS, and in-app notifications with ML-based prioritization
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/api/ml_predict.php';

class ReminderService {
    private $conn;
    private $db;
    
    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }
    
    /**
     * Send reminder for a specific booking
     */
    public function sendBookingReminder($booking_id, $force = false) {
        try {
            // Get booking details
            $query = "SELECT 
                fb.id, fb.user_id, fb.facility_id, fb.booking_date, 
                fb.start_time, fb.end_time, fb.purpose, fb.status,
                u.full_name, u.email, u.phone,
                f.name as facility_name
            FROM facility_bookings fb
            JOIN users u ON fb.user_id = u.id
            JOIN facilities f ON fb.facility_id = f.id
            WHERE fb.id = :booking_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':booking_id', $booking_id);
            $stmt->execute();
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$booking) {
                return ['success' => false, 'error' => 'Booking not found'];
            }
            
            $user_stats = $this->getUserBookingStats($booking['user_id']);
            $ml_data = [
                'hour_of_day' => (int)date('H', strtotime($booking['start_time'])),
                'day_of_week' => (int)date('N', strtotime($booking['booking_date'])),
                'advance_booking_days' => $this->calculateAdvanceBookingDays($booking['booking_date']),
                'duration_hours' => (strtotime($booking['end_time']) - strtotime($booking['start_time'])) / 3600,
                'user_completion_rate' => $user_stats['user_completion_rate'],
                'is_weekend' => in_array(date('N', strtotime($booking['booking_date'])), [6, 7]) ? 1 : 0,
                'same_day_facility_demand' => $this->getFacilityDemand($booking['facility_id'], $booking['booking_date'])
            ];
            
            $noshow_prediction = predict_noshow($ml_data);
            $risk_level = $noshow_prediction['risk_level'] ?? 'unknown';
            
            // Check if reminder already sent
            if (!$force) {
                $check_query = "SELECT id FROM notification_logs 
                               WHERE booking_id = :booking_id 
                               AND type = 'reminder'
                               AND created_at > DATE_SUB(NOW(), INTERVAL 2 HOUR)";
                $check_stmt = $this->conn->prepare($check_query);
                $check_stmt->bindParam(':booking_id', $booking_id);
                $check_stmt->execute();
                
                if ($check_stmt->rowCount() > 0) {
                    return ['success' => false, 'error' => 'Reminder already sent recently'];
                }
            }
            
            // Generate reminder message
            $message = $this->generateReminderMessage($booking, $risk_level);
            
            // Send via multiple channels based on risk level
            $results = [];
            
            // Always send email
            $results['email'] = $this->sendEmail($booking['email'], $booking['full_name'], $message);
            
            // Send SMS for high-risk bookings
            if ($risk_level === 'high' && !empty($booking['phone'])) {
                $results['sms'] = $this->sendSMS($booking['phone'], $message['sms']);
            }
            
            // Log notification
            $this->logNotification($booking_id, $booking['email'], $message['subject'], 'reminder', $results);
            
            return [
                'success' => true,
                'booking_id' => $booking_id,
                'risk_level' => $risk_level,
                'channels_used' => array_keys(array_filter($results)),
                'results' => $results
            ];
            
        } catch (Exception $e) {
            error_log("Reminder error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Generate context-aware reminder message
     */
    private function generateReminderMessage($booking, $risk_level) {
        $hours_until = $this->calculateHoursUntil($booking['booking_date'], $booking['start_time']);
        
        $urgency = '';
        $call_to_action = '';
        
        if ($risk_level === 'high') {
            $urgency = '🚨 IMPORTANT REMINDER';
            $call_to_action = "\n\n⚠️ Please confirm your attendance by replying to this email or calling the barangay office.";
        } elseif ($risk_level === 'medium') {
            $urgency = '📅 Booking Reminder';
            $call_to_action = "\n\nPlease let us know if you need to reschedule.";
        } else {
            $urgency = '📅 Friendly Reminder';
            $call_to_action = '';
        }
        
        $email_body = "{$urgency}\n\n";
        $email_body .= "Hello {$booking['full_name']},\n\n";
        $email_body .= "Your facility booking is coming up in {$hours_until} hours:\n\n";
        $email_body .= "📍 Facility: {$booking['facility_name']}\n";
        $email_body .= "📅 Date: " . date('F d, Y', strtotime($booking['booking_date'])) . "\n";
        $email_body .= "⏰ Time: " . date('h:i A', strtotime($booking['start_time'])) . " - " . date('h:i A', strtotime($booking['end_time'])) . "\n";
        $email_body .= "🎯 Purpose: {$booking['purpose']}\n";
        $email_body .= "{$call_to_action}\n\n";
        $email_body .= "Thank you!\n";
        $email_body .= "Barangay Kapasigan Management System";
        
        $sms_body = "Reminder: Your booking at {$booking['facility_name']} is in {$hours_until}h ({$booking['start_time']}). Reply to confirm.";
        
        return [
            'subject' => "Booking Reminder - Barangay Kapasigan",
            'email' => $email_body,
            'sms' => $sms_body
        ];
    }
    
    /**
     * Send email notification
     */
    private function sendEmail($to, $name, $message) {
        $subject = $message['subject'];
        $body = $message['email'];
        
        $html_message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; background: #f9f9f9; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: white; padding: 30px; border-radius: 0 0 10px 10px; }
                .footer { text-align: center; margin-top: 20px; color: #777; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>🏛️ Barangay Kapasigan</h2>
                    <p>Facility Booking Reminder</p>
                </div>
                <div class='content'>
                    " . nl2br(htmlspecialchars($body)) . "
                </div>
                <div class='footer'>
                    <p>This is an automated reminder from Barangay Kapasigan Management System</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: Barangay Kapasigan <noreply@barangaykapasigan.gov.ph>" . "\r\n";
        
        return mail($to, $subject, $html_message, $headers);
    }
    
    /**
     * Send SMS notification (placeholder - integrate with SMS provider)
     */
    private function sendSMS($phone, $message) {
        // TODO: Integrate with SMS provider (Twilio, etc.)
        // For now, just log it
        error_log("SMS to {$phone}: {$message}");
        return true;
    }
    
    /**
     * Log notification in database
     */
    private function logNotification($booking_id, $recipient, $message, $type, $results) {
        $status = $results['email'] ? 'sent' : 'failed';
        
        $query = "INSERT INTO notification_logs 
                  (booking_id, recipient, type, message, status, created_at) 
                  VALUES (:booking_id, :recipient, :type, :message, :status, NOW())";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':booking_id', $booking_id);
        $stmt->bindParam(':recipient', $recipient);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':message', $message);
        $stmt->bindParam(':status', $status);
        
        return $stmt->execute();
    }
    
    /**
     * Get user booking statistics
     */
    private function getUserBookingStats($user_id) {
        $query = "SELECT 
            COUNT(*) as total_bookings,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count
        FROM facility_bookings
        WHERE user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $approval_rate = $result['total_bookings'] > 0 
            ? $result['approved_count'] / $result['total_bookings'] 
            : 0.5;
        
        $completion_rate = $result['approved_count'] > 0 
            ? $result['completed_count'] / $result['approved_count'] 
            : 0.5;
        
        return [
            'user_approval_rate' => $approval_rate,
            'user_completion_rate' => $completion_rate,
            'total_bookings' => $result['total_bookings']
        ];
    }
    
    /**
     * Calculate advance booking days
     */
    private function calculateAdvanceBookingDays($booking_date) {
        $booking = new DateTime($booking_date);
        $today = new DateTime();
        $interval = $today->diff($booking);
        return $interval->days;
    }
    
    /**
     * Calculate hours until booking
     */
    private function calculateHoursUntil($booking_date, $start_time) {
        $booking_time = new DateTime($booking_date . ' ' . $start_time);
        $now = new DateTime();
        $interval = $now->diff($booking_time);
        return $interval->h + ($interval->days * 24);
    }
    
    /**
     * Get facility demand for a specific date
     */
    private function getFacilityDemand($facility_id, $date) {
        $query = "SELECT COUNT(*) FROM facility_bookings 
                  WHERE facility_id = :facility_id 
                  AND booking_date = :date 
                  AND status IN ('approved', 'pending')";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':facility_id', $facility_id);
        $stmt->bindParam(':date', $date);
        $stmt->execute();
        
        return $stmt->fetchColumn();
    }
}

// Handle CLI execution
if (php_sapi_name() === 'cli') {
    $reminder_service = new ReminderService();
    
    if (isset($argv[1])) {
        $booking_id = intval($argv[1]);
        $result = $reminder_service->sendBookingReminder($booking_id);
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "Usage: php send_reminder_message.php <booking_id>\n";
    }
}
?>
