<?php
function sendEmail(string $to, string $subject, string $body) {
    // Local XAMPP installations usually do not have SMTP configured.
    // Returning false makes the forgot-password page show a safe local reset link.
    return false;
}
?>
