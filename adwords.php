 <?php
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


// Initialize response message
$responseMessage = '';
// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
 
    $fname = $_POST['fname'] ?? '';
    $lname = $_POST['lname'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $location = $_POST['location'] ?? '';
    $adate = $_POST['adate'] ?? '';
    $ddate = $_POST['ddate'] ?? '';
    $adults = $_POST['adults'] ?? '';
    $children = $_POST['children'] ?? '';
    $questions = $_POST['questions'] ?? '';

    $mail = new PHPMailer(true);

    if (empty($fname) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $responseMessage = '<div style="color:red;">Invalid form data. Please check your inputs.</div>';
    } else {
        try {
 
            // Create PHPMailer instance
            $mail = new PHPMailer(true);

            // Server settings
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'generic.tribalsand@gmail.com';
            $mail->Password = 'cpozxrtucvldttmc';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            // Send email to admin
            $mail->setFrom($email, $fname);
            $mail->addAddress('san@sevamedcare.com');
            $mail->addAddress('spandey@sevamedcare.com');
            $mail->addAddress('jp@tribalsand.com');
            $mail->addAddress('info@tribalsand.com');
            $mail->addAddress('reservations@tribalsand.com');
            
            $mail->isHTML(true);
            $mail->Subject = 'New Form Submission';
 
            $mail->Body    = "
                <!DOCTYPE html>
            <html>
            <head>
                <meta charset='utf-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>New Form Submission</title>
                <style>
                    body {
                        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                        line-height: 1.6;
                        color: #333333;
                        background-color: #f5f5f5;
                        margin: 0;
                        padding: 0;
                    }
                    .container {
                        max-width: 600px;
                        margin: 0 auto;
                        padding: 20px;
                    }
                    .email-header {
                        background-color: #4a6fa5;
                        color: white;
                        padding: 20px;
                        text-align: center;
                        border-top-left-radius: 6px;
                        border-top-right-radius: 6px;
                    }
                    .email-header h2 {
                        margin: 0;
                        font-weight: 500;
                    }
                    .content {
                        background-color: #ffffff;
                        padding: 30px;
                        border-bottom-left-radius: 6px;
                        border-bottom-right-radius: 6px;
                        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                    }
                    .info-item {
                        margin-bottom: 15px;
                        padding-bottom: 15px;
                        border-bottom: 1px solid #eeeeee;
                    }
                    .info-item:last-child {
                        margin-bottom: 0;
                        padding-bottom: 0;
                        border-bottom: none;
                    }
                    .info-label {
                        font-weight: 600;
                        display: block;
                        margin-bottom: 5px;
                        color: #4a6fa5;
                    }
                    .info-value {
                        display: block;
                        font-size: 16px;
                    }
                    .footer {
                        text-align: center;
                        margin-top: 20px;
                        font-size: 12px;
                        color: #999999;
                    }
                    @media only screen and (max-width: 480px) {
                        .container {
                            width: 100%;
                            padding: 10px;
                        }
                        .email-header {
                            padding: 15px;
                        }
                        .content {
                            padding: 20px;
                        }
                    }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='email-header'>
                        <h2>New Form Submission</h2>
                    </div>
                    <div class='content'>
                        <div class='info-item'>
                            <span class='info-label'>First Name</span>
                            <span class='info-value'>{$fname}</span>
                        </div>
                        <div class='info-item'>
                            <span class='info-label'>Last Name</span>
                            <span class='info-value'>{$lname}</span>
                        </div>
                        <div class='info-item'>
                            <span class='info-label'>Email</span>
                            <span class='info-value'>{$email}</span>
                        </div>
                        <div class='info-item'>
                            <span class='info-label'>Phone</span>
                            <span class='info-value'>" . htmlspecialchars($phone) . "</span>
                        </div>
                        <div class='info-item'>
                            <span class='info-label'>Location</span>
                            <span class='info-value'>{$location}</span>
                        </div>
                        <div class='info-item'>
                            <span class='info-label'>Arrival Date</span>
                            <span class='info-value'>{$adate}</span>
                        </div>

                        <div class='info-item'>
                            <span class='info-label'>Departure Date</span>
                            <span class='info-value'>{$ddate}</span>
                        </div>

                        <div class='info-item'>
                            <span class='info-label'>Adults</span>
                            <span class='info-value'>{$adults}</span>
                        </div>

                        <div class='info-item'>
                            <span class='info-label'>Children</span>
                            <span class='info-value'>{$children}</span>
                        </div>

                        <div class='info-item'>
                            <span class='info-label'>Message / Questions</span>
                            <span class='info-value'>{$questions}</span>
                        </div>

                    </div>
                    <div class='footer'></br>
                    NOTE :  This is an automated notification from adwords. Please do not reply to this email.
                    </div>
                </div>
            </body>
            </html>
            ";
                    // echo"<pre>";
                    // print_r($mail->Body);
                    // exit;
                    $mail->send();
                    $responseMessage = '<div style="color:green;">Thank you! Your message has been sent successfully.</div>';
                    $hide = 1;
                } catch (Exception $e) {
                    $responseMessage = '<div style="color:red;">Mailer Error: ' . $mail->ErrorInfo . '</div>';
                }
            }
        }

?>

 <!DOCTYPE html>
 <html lang="en">




 <head>
     <meta charset="UTF-8">
     <meta name="viewport" content="width=device-width, initial-scale=1.0">
     <title>TribalSand | Contact Us</title>
     <link rel="icon" href="images/favicon.png">
     <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
     <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
     <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.1/font/bootstrap-icons.min.css"
         rel="stylesheet">
     <link rel="stylesheet" href="css/main.css">
     <?php include 'includes/g-tag-head.php'; ?>
     <!-- IntlTelInput CSS -->
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/css/intlTelInput.css">


     <style>
     .hero-section {
         position: relative;
         min-height: 60vh;
         display: flex;
         align-items: center;
         justify-content: center;
         color: white;
         text-align: center;
         overflow: hidden;
         height: 100vh;
     }

     .hero-overlay {
         position: absolute;
         top: 0;
         left: 0;
         right: 0;
         bottom: 0;
         background: rgba(0, 0, 0, 0.4);
         z-index: 1;
     }

     .slideshow-image {
         position: absolute;
         top: 0;
         left: 0;
         width: 100%;
         height: 100%;
         background-position: center;
         background-size: cover;
         opacity: 0;
         transition: opacity 1s ease-in-out;
     }

     .active {
         opacity: 1;
     }

     .booking-form {
         background-color: #f8f9fa;
         padding: 50px 0;
         max-width: 600px;
         margin: 0 auto;
         padding: 2rem;
         border-radius: 8px;
         box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
         background-color: white;
     }

     .booking-form {
         background: white;
         padding: 2rem;
         border-radius: 10px;
         box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
         width: 100%;
         max-width: 800px;
         margin: 1rem;
     }

     .form-select,
     .form-control {
         padding: 0.75rem;
         border: 1px solid #dee2e6;
         border-radius: 5px;
         color: #495057;
     }

     .input-group-text {
         background-color: transparent;
         border: 1px solid #dee2e6;
     }

     .guest-counter {
         display: flex;
         align-items: center;
         border: 1px solid #dee2e6;
         border-radius: 5px;
         overflow: hidden;
     }

     .counter-btn {
         background: transparent;
         border: none;
         padding: 0.75rem 1.5rem;
         cursor: pointer;
         font-size: 1.25rem;
         color: #495057;
         transition: background-color 0.2s;
     }

     .counter-btn:hover {
         background-color: #f8f9fa;
     }

     .counter-input {
         width: 60px;
         text-align: center;
         border: none;
         border-left: 1px solid #dee2e6;
         border-right: 1px solid #dee2e6;
         border-radius: 0;
     }

     .counter-input:focus {
         outline: none;
         box-shadow: none;
     }

     .btn-submit {
         background-color: #000;
         color: white;
         padding: 0.75rem;
         border-radius: 5px;
         border: none;
         transition: background-color 0.2s;
     }

     .btn-submit:hover {
         background-color: #333;
     }

     /* Remove spinner from number input */
     input[type="number"]::-webkit-inner-spin-button,
     input[type="number"]::-webkit-outer-spin-button {
         -webkit-appearance: none;
         margin: 0;
     }

     /* .contact-info {
            background-color: #fff;
            padding: 30px;
            border-radius: 5px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
        }

        .contact-info .list-group-item {
            border: none;
            padding: 10px 0;
        } */

     .btn-book {
         background-color: #000;
         color: white;
         padding: 10px 25px;
         border-radius: 0;
         transition: all 0.3s ease;
     }

     .btn-book:hover {
         background-color: #333;
         transform: scale(0.98);
     }

     .form-control {
         margin-bottom: 1rem;
     }

     .guest-counter {
         display: flex;
         align-items: center;
         gap: 1rem;
     }

     .counter-btn {
         width: 32px;
         height: 32px;
         border-radius: 50%;
         border: 1px solid #dee2e6;
         background: white;
         display: flex;
         align-items: center;
         justify-content: center;
         cursor: pointer;
     }

     .counter-input {
         width: 60px;
         text-align: center;
     }

     .booking-section {
         background-color: #3e4444;
         padding: 2.5rem 0;
     }

     .subtitle {
         text-transform: uppercase;
         margin-bottom: 0.5rem;
         color: #a2a2a2;
         font-family: "Manrope", Sans-serif;
         font-size: 18px;
         font-weight: 500;
         letter-spacing: 1.2px;
         margin-bottom: 40px;
     }

     .main-title {
         color: #ffffff;
         font-family: "Manrope", Sans-serif;
         font-size: 46px;
         font-weight: 0;
         text-transform: uppercase;
         letter-spacing: 6px;
         margin-bottom: 20px;
     }

     .separator {
         border: none;
         border-top: 2px solid #ffd700;

         width: 13%;
         margin: 0 auto;
     }

     .booking-card {
         background: white;
         border-radius: 8px;
         padding: 40px;
         box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
     }

     .booking-title {
         color: #333;
         font-size: 28px;
         margin-bottom: 30px;
     }

     .contact-list {
         margin: 25px 0;
     }

     .contact-list li {
         margin: 15px 0;
         font-size: 16px;
     }

     .contact-list a {
         color: #333;
         text-decoration: none;
         transition: color 0.3s ease;
     }

     .contact-list a:hover {
         color: #666;
     }

     .book-btn {
         background-color: #c5a47e !important;
         border: none;
         padding: 12px 50px;
         font-size: 20px;
         color: white !important;
         text-decoration: none;
         display: inline-block;
         margin-top: 10px;
         font-weight: 500;
         transition: background-color 0.3s ease, transform 0.3s ease;
         animation: pulseAnimation 1.5s infinite ease-in-out;
     }

     .book-btn:hover {
         background-color: #b39371 !important;
         transform: scale(0.95);
     }

     .book-btn:active {
         transform: scale(0.95);
     }

     .fa-long-arrow-alt-right {
         margin-left: 10px;
     }

     .enquiry-section {
         background-color: #3e4444;
         padding: 40px 0;
         color: #fff;
     }

     .enquiry-title {
         margin-bottom: 40px;
         text-align: center;
         color: #a2a2a2;
         font-family: "Montserrat", Sans-serif;
         font-size: 18px;
         font-weight: 300;
         letter-spacing: 1.2px;
     }

     .main-title {
         margin-bottom: 20px;
         color: #ffffff;
         font-family: "Montserrat", Sans-serif;
         font-size: 46px;

         text-transform: uppercase;
         letter-spacing: 6px;
     }

     .divider {
         border-top: 2px solid #c9a96a;
         width: 13%;
         margin: 0 auto;
         margin-bottom: 30px;
     }

     .description {
         text-align: center;
         color: #cbcbcb;
         font-family: "Piazzolla", Sans-serif;
         font-size: 25px;
         font-weight: 200;
         letter-spacing: 2px;
         margin-bottom: 50px;
     }

     .description a {
         color: #cbcbcb;
         text-decoration: none;
         border-bottom: 1px solid rgba(255, 255, 255, 0.3);
         transition: all 0.3s ease;
     }

     .description a:hover {
         border-bottom-color: #fff;
     }

     .ownerrez-container {
         background: #fff;
         padding: 20px;
         border-radius: 4px;
         margin-top: 30px;
     }

     .ownerrez-widget iframe {
         width: 100%;
         min-height: 600px;
         border: none;
     }

     .logo-container {
         background: #fff;
         padding: 15px;
         margin-top: 30px;
         display: inline-block;
     }

     .logo-container img {
         max-width: 900px;
         height: auto;
     }

     .footer {
         background-color: #2d2d2d;
         color: white;
         padding: 4rem 0;
     }

     .footer h2 {
         font-size: 1.5rem;
         margin-bottom: 1.5rem;
         font-weight: 500;
     }

     .footer-logo {
         width: 150px;
         height: auto;
     }

     .social-icons a {
         color: white;
         text-decoration: none;
         margin-right: 1rem;
         font-size: 1.25rem;
         transition: color 0.3s ease;
     }

     .social-icons a:hover {
         color: #4dabf7;
     }

     .footer-links {
         list-style: none;
         padding: 0;
         margin: 0;
     }

     .footer-links li {
         margin-bottom: 0.75rem;
     }

     .footer-links a {
         color: white;
         text-decoration: none;
         transition: color 0.3s ease;
     }

     .footer-links a:hover {
         color: #4dabf7;
     }

     /* .contact-info li {
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
        }

        .contact-info i {
            margin-right: 0.5rem;
            width: 20px;
        } */

     .contact-link {
         color: white;
         text-decoration: none;
         transition: color 0.3s ease;
     }

     .contact-link:hover {
         color: #4dabf7;
     }

     .company-name {
         color: #4dabf7;
         font-weight: bold;
     }

     @media (max-width: 768px) {
         .footer {
             text-align: center;
         }

         .social-icons {
             justify-content: center;
             margin-bottom: 2rem;
         }

         .footer h2 {
             margin-top: 2rem;
         }
     }

     .card {
         background-color: #fff;
         color: #333;
         border: none;
         border-radius: 0;
     }

     .btn-primary {
         background-color: #007bff;
         border-color: #007bff;
     }

     .btn-primary:hover {
         background-color: #0056b3;
         border-color: #004d99;
     }

     /* Default logo container styling */
     .logo-container {
         background: #fff;
         padding: 15px;
         margin-top: 30px;
         display: inline-block;
     }

     .logo-container img {
         max-width: 100%;
         height: auto;
     }

     .tripBook {
         cursor: pointer;
         border: none;
         border-radius: 0.25rem;
         padding: 6px 12px;
         color: rgb(255, 255, 255);
         background-color: rgb(0, 0, 0);
     }

     @media (max-width: 767px) {
         .logo-container {
             padding: 10px;
             margin-top: 20px;
         }

         .logo-container img {
             max-width: 80%;
         }
     }

     @media (min-width: 768px) and (max-width: 1024px) {
         .logo-container img {
             max-width: 90%;
         }
     }

     @media (min-width: 1025px) {
         .logo-container img {
             max-width: 900px;
         }
     }
     </style>

     <style>
     .error-message {
         color: #dc3545;
         font-size: 14px;
         margin-top: 5px;
         display: none;
     }

     /* Override some IntlTelInput styles for better integration */
     .iti {
         width: 100%;
     }

     .iti__flag {
         background-image: url("https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/img/flags.png");
     }

     @media (-webkit-min-device-pixel-ratio: 2),
     (min-resolution: 192dpi) {
         .iti__flag {
             background-image: url("https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/img/flags@2x.png");
         }
     }




     .valid-feedback {
         color: #28a745;
         display: none;
         font-size: 14px;
         margin-top: 5px;
     }
     </style>






 </head>

 <body>
     <?php include 'includes/g-tag-body.php'; ?>

     <?php include 'includes/header.php'; ?>

     <!-- Hero Section -->
     <section class="hero-section">
         <!-- Slideshow images -->
         <div class="slideshow-image active" style="
          background-image: url('images/October-28-2021-6-PM.36.05-2709-HDR-min-scaled.jpg.webp');
        "></div>
         <div class="slideshow-image" style="background-image: url('images/brown-bg-1')"></div>
         <div class="slideshow-image" style="
          background-image: url('images/Tribal-Sand-lion-White-logo-768x923.png');
        "></div>

         <!-- Hero overlay -->
         <div class="hero-overlay"></div>

         <!-- Container for content -->
         <div class="container" style="position: relative; z-index: 2">
             <div class="row">
                 <div class="col-12">
                     <!-- Heading with styling -->
                     <h1 class="display-4 mb-4" style="
                color: #ffffff;
                font-family: 'Montserrat', Sans-serif;
                font-size: 62px;
                font-weight: 300;
                text-transform: uppercase;
                line-height: 2.7em;
                letter-spacing: 10px;
                text-shadow: 0px 0px 30px #000000;
              ">
                         CONTACT US
                     </h1>
                     <!-- Downward arrow icon with bounce animation -->
                     <i class="fas fa-angle-down fa-2x bounce"></i>
                 </div>
             </div>
         </div>
     </section>

     <!-- <section class="booking-section">
        <div class="container">
            <div class="row">
                <div class="col-12 text-center">
                    <p class="subtitle">BOOK A TRIP DIRECTLY</p>
                    <h2 class="main-title">BOOK YOUR TRIP</h2>
                    <hr class="separator" />
                </div>
            </div>
        </div>
    </section> -->



     <section class="enquiry-section pb-0">
         <div class="container">
             <div class="row justify-content-center">
                 <div class="col-md-8 text-center">
                     <!-- Enquiry Title -->
                     <p class="enquiry-title">MAKE AN ENQUIRY</p>

                     <!-- Main Heading -->
                     <p class="main-title" style="font-weight: 200 !important">
                         PLAN YOUR TRIP
                     </p>

                     <!-- Divider -->
                     <div class="divider"></div>

                     <!-- Description -->
                     <p class="description">
                         Please complete the form below to make a booking enquiry,<br />
                         or contact us <a href="mailto:reservations@tribalsand.com" style="color: #4ebbab;">directly</a>
                     </p>
                 </div>
             </div>
         </div>
     </section>


     <style>
     .datadiv {
         background-color: #3c4446;
         color: #a0a0a0;
         padding: 2rem 0;
     }

     .form-label {
         color: #a0a0a0;
         font-size: 0.9rem;
         margin-bottom: 0.5rem;
     }

     /* Base styles for all form controls */
     .form-control,
     .form-select,
     .input-group,
     .input-group-text {
         height: 48px;
         border-radius: 0;
         border: none;
         background-color: white;
     }

     .form-control,
     .form-select {
         padding: 0 1rem;
     }

     /* Input group specific styles */
     .input-group {
         align-items: stretch;
     }

     .input-group-text {
         padding: 0 1rem;
         display: flex;
         align-items: center;
         border-right: 1px solid #dee2e6;
     }

     .input-group-text i {
         color: #6c757d;
         font-size: 1.2rem;
         display: flex;
         align-items: center;
         height: 100%;
     }

     /* Phone group specific styles */
     .phone-group .form-select {
         max-width: 140px;
         border-left: 1px solid #dee2e6;
         margin-bottom: 0;
     }

     /* Date input specific styles */
     .date-input {
         position: relative;
     }

     .date-input i {
         position: absolute;
         right: 15px;
         top: 50%;
         transform: translateY(-50%);
         color: #6c757d;
         font-size: 1.2rem;
         pointer-events: none;
     }

     /* Textarea specific styles */
     textarea.form-control {
         height: auto;
         min-height: 120px;
         padding: 1rem;
     }

     /* Focus states */
     .form-control:focus,
     .form-select:focus {
         border: 1px solid #c5a572;
         box-shadow: none;
     }

     /* Submit button styles */
     .btn-submit {
         background-color: #c5a572;
         border: none;
         color: white;
         padding: 0 2rem;
         text-transform: uppercase;
         letter-spacing: 1px;
         font-size: 0.9rem;
         border-radius: 0;
         height: 48px;
         min-width: 200px;
         display: flex;
         align-items: center;
         justify-content: center;
     }

     .btn-submit:hover {
         background-color: #b39461;
         color: white;
     }

     /* 
        .container {
            max-width: 1000px;
        } */
     </style>

     <div class="datadiv">
         <div class="container">
             <form method="POST" action="">
                 <link rel="stylesheet" href="css/booking.css">
                 <script src="js/datepicker.js" defer></script>
                 <div class="row">
                     <div class="col-md-6 mb-2">
                         <label class="form-label">First Name</label>
                         <input type="text" class="form-control form-control-sm" name="fname"
                             placeholder="Enter first name" required />
                     </div>
                     <div class="col-md-6 mb-2">
                         <label class="form-label">Last Name</label>
                         <input type="text" class="form-control form-control-sm" name="lname"
                             placeholder="Enter last name" required />
                     </div>
                 </div>

                 <div class="row">
                     <div class="col-md-6 mb-3">
                         <label class="form-label">Email</label>
                         <div class="input-group">
                             <span class="input-group-text">
                                 <i class="bi bi-envelope"></i>
                             </span>
                             <input type="email" class="form-control" name="email" placeholder="Enter email address"
                                 required />
                         </div>
                     </div>
                     <div class="col-md-6 mb-3">
                         <label class="form-label">Phone</label>
                         <div class="input-group phone-group">
                             <span class="input-group-text">
                                 <i class="bi bi-telephone"></i>
                             </span>
                             <div class="phone-input-container" style="max-width: 200px">
                                 <input type="tel" id="phone" name="phone" class="form-control">
                                 <div class="error-message" id="error-msg"></div>
                                 <div class="valid-feedback" id="valid-msg">✓ Valid phone number</div>
                             </div>
                         </div>
                     </div>
                 </div>

                 <div class="row">
                     <div class="col-md-4 mb-3">
                         <label class="form-label">Location</label>
                         <select class="form-select" name="location" required>
                            <option value="" selected disabled>- Select Location -</option>
                            <option value="My Amani-Entire Retreat-Vipingo">My Amani - Entire Retreat - Vipingo</option>
                            <option value="Zuri-Entire Retreat-Watamu">Zuri - Entire Retreat - Watamu</option>
                            <option value="Maya Kobe-Entire Retreat-Kilifi">Maya Kobe - Entire Retreat - Kilifi</option>
                            <option value="Zuri-Private Suite-Watamu">Zuri - Private Suite - Watamu</option>
                            <option value="Maya Kobe-Private Suite-Kilifi">Maya Kobe - Private Suite - Kilifi</option>
                            <option value="Enkare Bofa-Entire Retreat-Kilifi">Enkare Bofa - Entire Retreat - Kilifi</option>
                            <option value="Sandbox-Entire Retreat-Kilifi">Sandbox - Entire Retreat - Kilifi</option>
                             <!-- <option value="" selected disabled>- Select Location -</option>
                             <option value="enkare-bofa">Enkare Bofa</option>
                             <option value="maya-kobe-cottages">Maya Kobe Cottages</option>    
                             <option value="maya-kobe-full-rental">Maya Kobe- Full Rental</option>
                             <option value="maya-kobe-main-house">Maya Kobe Main House</option>
                             <option value="my-amani-full-rental">My Amani- Full Rental</option>
                             <option value="my-amani-premium-sea-view-dblb">My Amani Premium Sea View - DBLB</option>
                             <option value="my-amani-premium-sea-view-sglb">My Amani Premium Sea View - SGLB</option>
                             <option value="my-amani-superior-garden-view-dblb">My Amani Superior Garden View - DBLB
                             </option>
                             <option value="my-amani-superior-garden-view-sglb">My Amani Superior Garden View - SGLB
                             </option>
                             <option value="my-amani-superior-sea-view-dblb">My Amani Superior Sea View - DBLB</option>
                             <option value="my-amani-superior-sea-view-sglb">My Amani Superior Sea View - SGLB</option>
                             <option value="my-amani-twin-garden-view-dblb">My Amani Twin Garden View - DBLB</option>
                             <option value="my-amani-twin-garden-view-sglb">My Amani Twin Garden View - SGLB</option>
                             <option value="my-amani-twin-sea-view-dblb">My Amani Twin Sea View - DBLB</option>
                             <option value="my-amani-twin-sea-view-sglb">My Amani Twin Sea View - SGLB</option>
                             <option value="zuri-by-tribal-sand">Zuri by Tribal Sand</option> -->
                         </select>

                     </div>
                     <div class="col-md-4 mb-3">
                         <label class="form-label">Arrival Date</label>
                         <div class="date-input">
                             <button type="button" class="dp-btn" data-dp-role="ci" data-dp-pair="adwTrip" data-dp-target="adateInp" data-dp-placeholder="Add date">Add date</button>
                             <input type="hidden" name="adate" id="adateInp">
                             <i class="bi bi-calendar"></i>
                         </div>
                     </div>
                     <div class="col-md-4 mb-3">
                         <label class="form-label">Departure Date</label>
                         <div class="date-input">
                             <button type="button" class="dp-btn" data-dp-role="co" data-dp-pair="adwTrip" data-dp-target="ddateInp" data-dp-placeholder="Add date">Add date</button>
                             <input type="hidden" name="ddate" id="ddateInp">
                             <i class="bi bi-calendar"></i>
                         </div>
                     </div>
                 </div>

                 <div class="row">
                     <div class="col-md-6 mb-3">
                         <label class="form-label">Adults</label>
                         <select class="form-select" name="adults" required>
                             <option value="" selected disabled>
                                 - Select Number of Adults -
                             </option>
                             <option value="1">1 Adult</option>
                             <option value="2">2 Adults</option>
                             <option value="3">3 Adults</option>
                             <option value="4">4 Adults</option>
                             <option value="5">5 Adults</option>
                             <option value="6">6 Adults</option>
                             <option value="7">7 Adults</option>
                             <option value="8">8 Adults</option>
                             <option value="9">9 Adults</option>
                             <option value="10">10 Adults</option>
                             <option value="11">11 Adults</option>
                             <option value="12">12 Adults</option>
                             <option value="13">13 Adults</option>
                             <option value="14">14 Adults</option>
                             <option value="15">15 Adults</option>
                             <option value="16">16 Adults</option>
                             <option value="17">17 Adults</option>
                             <option value="18">18 Adults</option>
                             <option value="19">19 Adults</option>
                             <option value="20">20 Adults</option>
                         </select>
                     </div>
                     <div class="col-md-6 mb-3">
                         <label class="form-label">Children</label>
                         <select class="form-select" name="children" required>
                             <option value="" selected disabled>
                                 - Select Number of Children -
                             </option>
                             <option value="0">No Children</option>
                             <option value="1">1 Child</option>
                             <option value="2">2 Children</option>
                             <option value="3">3 Children</option>
                             <option value="4">4 Children</option>
                             <option value="5">5 Children</option>
                             <option value="6">6 Children</option>
                             <option value="7">7 Children</option>
                             <option value="8">8 Children</option>
                             <option value="9">9 Children</option>
                             <option value="10">10 Children</option>
                         </select>
                     </div>
                 </div>

                 <div class="mb-4">
                     <label class="form-label">Comments/Questions</label>
                     <textarea class="form-control" rows="4" name="questions"
                         placeholder="Please enter any additional comments or special requests"></textarea>
                 </div>

                 <!-- <button type="submit" class="btn btn-submit">Submit Inquiry</button> -->
                 <input type="submit" value="Submit" class="submit-btn btn btn-info text-white fw-semibold px-4 py-2"
                     id="submitBtn">


                 <div class="alert alert-success <?php echo (isset($hide)) ? '' : 'd-none'; ?>" id="successAlert"
                     role="alert">
                     <p id="responseMessage"><?php echo $responseMessage ?></p>
                 </div>

             </form>

             <div id="formResponse"></div>


             <!-- OwnerRez Booking/Inquiry widget -->
             <!-- <div class="ownerrez-widget" data-widget-type="Booking/Inquiry" data-widgetId="9acb00f0fefb49f9abbda5c80075e60c"></div>
            <script src="https://app.ownerrez.com/widget.js"></script> -->
         </div>
     </div>

     <script>
     // Set minimum date to today for arrival date
     const today = new Date().toISOString().split("T")[0];
     document.querySelector('input[type="date"]').min = today;

     // Update departure date min based on arrival date
     const arrivalInput = document.querySelectorAll('input[type="date"]')[0];
     const departureInput = document.querySelectorAll('input[type="date"]')[1];

     arrivalInput.addEventListener("change", function() {
         departureInput.min = this.value;
         if (departureInput.value && departureInput.value < this.value) {
             departureInput.value = this.value;
         }
     });
     </script>



     <?php include 'includes/footer.php'; ?>

     <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
     <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
     <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
     <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>

     <!-- IntlTelInput Script -->
     <script src="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/intlTelInput.min.js"></script>

     <script>
     document.getElementById('contactForm').addEventListener('submit', function(e) {

         e.preventDefault(); // Prevent page reload

         const form = e.target;
         const formData = new FormData(form);

         fetch('TRIBAL_SAND/adwords.php', {
                 method: 'POST',
                 body: formData
             })
             .then(response => response.text())
             .then(result => {
                 document.getElementById('formResponse').innerHTML = result;
                 form.reset(); // Optional: reset form
             })
             .catch(error => {
                 document.getElementById('formResponse').innerHTML = 'Something went wrong.';
                 console.error('Error:', error);
             });
     });
     </script>






     <script>
     const phoneInputField = document.querySelector("#phone");
     const errorMsg = document.querySelector("#error-msg");
     const validMsg = document.querySelector("#valid-msg");

     // Error messages for different error codes
     const errorMap = [
         "Invalid number",
         "Invalid country code",
         "Too short",
         "Too long",
         "Invalid number"
     ];

     // Initialize the intl-tel-input
     const phoneInput = window.intlTelInput(phoneInputField, {
         preferredCountries: ["us", "co", "in", "de"],
         utilsScript: "https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/utils.js",
         separateDialCode: true,
         autoPlaceholder: "aggressive",
         formatOnDisplay: true,
         allowDropdown: true,
         autoHideDialCode: false,
         nationalMode: true
     });

     // Function to reset messages
     const reset = () => {
         phoneInputField.classList.remove("error");
         errorMsg.style.display = "none";
         validMsg.style.display = "none";
     };

     // Validate on input
     const validatePhoneNumber = () => {
         reset();

         if (phoneInputField.value.trim()) {
             if (phoneInput.isValidNumber()) {
                 validMsg.style.display = "block";
                 return true;
             } else {
                 phoneInputField.classList.add("error");
                 const errorCode = phoneInput.getValidationError();
                 errorMsg.textContent = errorMap[errorCode] || "Invalid number";
                 errorMsg.style.display = "block";
                 return false;
             }
         }
         return false;
     };

     // Event listeners
     phoneInputField.addEventListener("blur", validatePhoneNumber);
     phoneInputField.addEventListener("change", validatePhoneNumber);
     phoneInputField.addEventListener("keyup", validatePhoneNumber);

     // Country change event
     phoneInput.promise.then(() => {
         phoneInputField.addEventListener("countrychange", () => {
             reset();
             validatePhoneNumber();
         });
     });

     // Example of getting the full number with country code
     const getFullNumber = () => {
         if (validatePhoneNumber()) {
             return phoneInput.getNumber(); // E.164 format
         }
         return null;
     };
     </script>





     <script>
     $(document).ready(function() {
         // Handle click on parent dropdown toggle (including submenus)
         $('.dropdown-toggle').on('click', function(e) {
             e.preventDefault();
             e.stopPropagation();

             var $dropdownMenu = $(this).next('.dropdown-menu');
             var isSubmenu = $dropdownMenu.hasClass('dropdown-submenu');

             // If clicking on a parent dropdown (not a submenu), close other dropdowns
             if (!isSubmenu) {
                 $('.dropdown-menu').not($dropdownMenu).removeClass('show');
                 $('.dropdown-toggle').not($(this)).attr('aria-expanded', 'false');
             } else {
                 // If clicking on a submenu toggle, close other submenus at the same level
                 var $siblingSubmenus = $(this).closest('.dropdown-menu')
                     .find('.dropdown-submenu')
                     .not($dropdownMenu);

                 $siblingSubmenus.removeClass('show');
                 $siblingSubmenus.prev('.dropdown-toggle').attr('aria-expanded', 'false');
             }

             // Toggle the clicked dropdown (parent or submenu)
             $dropdownMenu.toggleClass('show');

             // Toggle aria-expanded attribute for accessibility
             $(this).attr('aria-expanded', function(_, attr) {
                 return attr === 'true' ? 'false' : 'true';
             });
         });

         // Close dropdowns when clicking outside
         $(document).on('click', function(e) {
             if (!$(e.target).closest('.dropdown').length) {
                 $('.dropdown-menu').removeClass('show');
                 $('.dropdown-toggle').attr('aria-expanded', 'false');
             }
         });

         // Prevent closing of the parent dropdown when clicking on a submenu toggle
         $('.dropdown-submenu > .dropdown-toggle').on('click', function(e) {
             e.stopPropagation(); // Prevent closing parent dropdown when clicking on submenu
         });
     });
     </script>


     <script>
     document.querySelectorAll('.custom-toggler').forEach(button => {
         button.addEventListener('click', function() {
             const expanded = this.getAttribute('aria-expanded') === 'true' || false;
             this.setAttribute('aria-expanded', !expanded);
         });
     });
     </script>
 </body>

 </html>