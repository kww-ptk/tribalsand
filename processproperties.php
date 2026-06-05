<?php

// PHP Processing (at the top of the file)
if ($_SERVER["REQUEST_METHOD"] == "POST"  ) {
    echo"live - sssss";
exit;
    header('Content-Type: application/json');

    $name = $_POST['Ename'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $email = $_POST['email'] ?? '';
    $message = $_POST['message'] ?? '';

    // ✅ You can add validation or saving logic here

    echo json_encode([
        "success" => true,
        "message" => "Form submitted successfully",
        "data" => compact("name", "phone", "email", "message")
    ]);
    exit;
}
?>