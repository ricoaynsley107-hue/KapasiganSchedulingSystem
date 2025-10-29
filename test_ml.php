<?php
// test_ml.php - Test ML Integration
require_once 'api/ml_predict.php';

echo "<h1>🧪 Testing ML Integration</h1>";

// Test data
$test_booking = [
    'hour_of_day' => 14,
    'day_of_week' => 2,
    'advance_booking_days' => 7,
    'duration_hours' => 2,
    'user_approval_rate' => 0.9,
    'user_completion_rate' => 0.85,
    'same_day_facility_demand' => 1,
    'is_weekend' => 0
];

echo "<h2>📊 Test Booking Data:</h2>";
echo "<pre>" . print_r($test_booking, true) . "</pre>";

echo "<h2>🌳 Decision Tree (Auto-Approval) Prediction:</h2>";
$approval_result = predict_approval($test_booking);
echo "<pre>" . print_r($approval_result, true) . "</pre>";

if ($approval_result['should_auto_approve']) {
    echo "<div style='background: #d5f4e6; padding: 15px; border-radius: 5px; color: #00b894;'>";
    echo "<strong>✅ RESULT: AUTO-APPROVE</strong><br>";
    echo "Confidence: " . round($approval_result['confidence'] * 100, 1) . "%";
    echo "</div>";
} else {
    echo "<div style='background: #ffeaa7; padding: 15px; border-radius: 5px; color: #d63031;'>";
    echo "<strong>⏳ RESULT: MANUAL REVIEW REQUIRED</strong><br>";
    echo "Confidence: " . round($approval_result['confidence'] * 100, 1) . "%";
    echo "</div>";
}

echo "<h2>📉 Logistic Regression (No-Show) Prediction:</h2>";
$noshow_result = predict_noshow($test_booking);
echo "<pre>" . print_r($noshow_result, true) . "</pre>";

if ($noshow_result['send_extra_reminder']) {
    echo "<div style='background: #fee; padding: 15px; border-radius: 5px; color: #c33;'>";
    echo "<strong>⚠️ HIGH NO-SHOW RISK</strong><br>";
    echo "Send extra reminders at 48h, 24h, and 2h before event";
    echo "</div>";
} else {
    echo "<div style='background: #d5f4e6; padding: 15px; border-radius: 5px; color: #00b894;'>";
    echo "<strong>✅ LOW NO-SHOW RISK</strong><br>";
    echo "Standard 24h reminder is sufficient";
    echo "</div>";
}

echo "<hr>";
echo "<h3>✅ ML Integration Working!</h3>";
echo "<p>Next steps:</p>";
echo "<ul>";
echo "<li>Integrate into booking creation process</li>";
echo "<li>Add ML dashboard for admins</li>";
echo "<li>Set up automated reminders</li>";
echo "</ul>";
?>