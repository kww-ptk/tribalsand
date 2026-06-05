<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

// Initialize response message
$responseMessage = '';
// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
     
    $name = $_POST['Ename'] ?? '';
    $email = $_POST['email'] ?? '';
    $message = $_POST['message'] ?? '';
    $phone = $_POST['phone'] ?? '';
    
    if (empty($name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $responseMessage = '<div style="color:red;">Invalid form data. Please check your inputs.</div>';
    } else {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'generic.tribalsand@gmail.com'; // Replace with your email
            $mail->Password   = 'cpozxrtucvldttmc';    // Replace with your app password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            // Email content
            $mail->setFrom('generic.tribalsand@gmail.com', 'testsender');
            $mail->addAddress('san@sevamedcare.com'); // Replace with receiver's email
            $mail->isHTML(true);
            $mail->Subject = 'New Contact Form Submission';
            $mail->Body    = "
                <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>New Agent Registration</title>
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
                <h2>New Property Inquiry Interest</h2>
            </div>
            <div class='content'>
                <div class='info-item'>
                    <span class='info-label'>Name</span>
                    <span class='info-value'>{$name}</span>
                </div>
                <div class='info-item'>
                    <span class='info-label'>Email Address</span>
                    <span class='info-value'>{$email}</span>
                </div>
                <div class='info-item'>
                    <span class='info-label'>Phone</span>
                    <span class='info-value'>{$phone}</span>
                </div>
                <div class='info-item'>
                    <span class='info-label'>Message</span>
                    <span class='info-value'>{$message}</span>
                </div>

            </div>
            <div class='footer'>
                This is an automated notification. Please do not reply to this email.
            </div>
        </div>
    </body>
    </html>
    ";

            $mail->send();
            $responseMessage = '<div style="color:green;">Thank you! Your message has been sent successfully.</div>';
            $hide = 1;
        } catch (Exception $e) {
            $responseMessage = '<div style="color:red;">Mailer Error: ' . $mail->ErrorInfo . '</div>';
        }
    }
}



















// use PHPMailer\PHPMailer\PHPMailer;
// use PHPMailer\PHPMailer\Exception; 
// require 'vendor/autoload.php'; 

// if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['ajax'])) {
     
// header('Content-Type: application/json');

// try { 

//     $name = $_POST['Ename'] ?? '';
//     $email = $_POST['email'] ?? '';
//     $phone = $_POST['phone'] ?? '';
//     $message = $_POST['message'] ?? '';

//     // Validate inputs
//     if (empty($name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
//         throw new Exception('Invalid input data');
//     }

//     // Create PHPMailer instance
//     $mail = new PHPMailer(true);

//     // Server settings
//     $mail->isSMTP();
//     $mail->Host = 'smtp.gmail.com';
//     $mail->SMTPAuth = true;
//     $mail->Username = 'generic.tribalsand@gmail.com';
//     $mail->Password = 'cpozxrtucvldttmc';
//     // $mail->Username = 'palaimanageacc@gmail.com';
//     // $mail->Password = 'mynudvjhcxijllnt';
//     $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
//     $mail->Port = 587;

//     // Send email to admin
//     $mail->setFrom($email, $name);
//     $mail->addAddress('san@sevamedcare.com');
//     $mail->addAddress('spandey@sevamedcare.com');
//     // $mail->addAddress('valentina@tribalsand.com');
//     // $mail->addAddress('federico@tribalsand.com');
//     $mail->isHTML(true);
//     $mail->Subject = 'New Property Inquiry from TribalSand Properties';
//     $mail->Body = "
//    <!DOCTYPE html>
//     <html>
//     <head>
//         <meta charset='utf-8'>
//         <meta name='viewport' content='width=device-width, initial-scale=1.0'>
//         <title>New Agent Registration</title>
//         <style>
//             body {
//                 font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
//                 line-height: 1.6;
//                 color: #333333;
//                 background-color: #f5f5f5;
//                 margin: 0;
//                 padding: 0;
//             }
//             .container {
//                 max-width: 600px;
//                 margin: 0 auto;
//                 padding: 20px;
//             }
//             .email-header {
//                 background-color: #4a6fa5;
//                 color: white;
//                 padding: 20px;
//                 text-align: center;
//                 border-top-left-radius: 6px;
//                 border-top-right-radius: 6px;
//             }
//             .email-header h2 {
//                 margin: 0;
//                 font-weight: 500;
//             }
//             .content {
//                 background-color: #ffffff;
//                 padding: 30px;
//                 border-bottom-left-radius: 6px;
//                 border-bottom-right-radius: 6px;
//                 box-shadow: 0 2px 5px rgba(0,0,0,0.1);
//             }
//             .info-item {
//                 margin-bottom: 15px;
//                 padding-bottom: 15px;
//                 border-bottom: 1px solid #eeeeee;
//             }
//             .info-item:last-child {
//                 margin-bottom: 0;
//                 padding-bottom: 0;
//                 border-bottom: none;
//             }
//             .info-label {
//                 font-weight: 600;
//                 display: block;
//                 margin-bottom: 5px;
//                 color: #4a6fa5;
//             }
//             .info-value {
//                 display: block;
//                 font-size: 16px;
//             }
//             .footer {
//                 text-align: center;
//                 margin-top: 20px;
//                 font-size: 12px;
//                 color: #999999;
//             }
//             @media only screen and (max-width: 480px) {
//                 .container {
//                     width: 100%;
//                     padding: 10px;
//                 }
//                 .email-header {
//                     padding: 15px;
//                 }
//                 .content {
//                     padding: 20px;
//                 }
//             }
//         </style>
//     </head>
//     <body>
//         <div class='container'>
//             <div class='email-header'>
//                 <h2>New Property Inquiry Interest</h2>
//             </div>
//             <div class='content'>
//                 <div class='info-item'>
//                     <span class='info-label'>Name</span>
//                     <span class='info-value'>{$name}</span>
//                 </div>
//                 <div class='info-item'>
//                     <span class='info-label'>Email Address</span>
//                     <span class='info-value'>{$email}</span>
//                 </div>
//                 <div class='info-item'>
//                     <span class='info-label'>Phone</span>
//                     <span class='info-value'>{$phone}</span>
//                 </div>
//                 <div class='info-item'>
//                     <span class='info-label'>Message</span>
//                     <span class='info-value'>{$message}</span>
//                 </div>

//             </div>
//             <div class='footer'>
//                 This is an automated notification. Please do not reply to this email.
//             </div>
//         </div>
//     </body>
//     </html>
//     ";
//     $mail->send();

//     // Send confirmation email to user
//     $mail->clearAddresses();
//     $mail->setFrom('sanketpalai007@gmail.com', 'Tribal Sand');
//     $mail->addAddress($email, $name);
//     $mail->addReplyTo('sanketpalai007@gmail.com');
//     $mail->Subject = 'Thank You for Your Interest in Tribal Sand';
//     $mail->Body = "
//    <!DOCTYPE html>
// <html>
// <head>
//     <meta charset='utf-8'>
//     <meta name='viewport' content='width=device-width, initial-scale=1.0'>
//     <title>Thank You for Your Interest in TribalSand</title>
//     <style>
//         body {
//             font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
//             line-height: 1.6;
//             color: #333333;
//             background-color: #f5f5f5;
//             margin: 0;
//             padding: 0;
//         }
//         .container {
//             max-width: 600px;
//             margin: 0 auto;
//             padding: 20px;
//         }
//         .email-header {
//             background-color: #1a3b5d;
//             text-align: center;
//             padding: 20px;
//             border-top-left-radius: 6px;
//             border-top-right-radius: 6px;
//         }
//         .company-name {
//             color: #ffffff;
//             font-size: 22px;
//             font-weight: bold;
//             margin: 0;
//             text-transform: uppercase;
//             letter-spacing: 1px;
//         }
//         .content {
//             background-color: #ffffff;
//             padding: 30px;
//             border-bottom-left-radius: 6px;
//             border-bottom-right-radius: 6px;
//             box-shadow: 0 2px 5px rgba(0,0,0,0.1);
//         }
//         .greeting {
//             font-size: 16px;
//             margin-bottom: 20px;
//         }
//         .message {
//             margin-bottom: 20px;
//         }
//         .documents {
//             background-color: #f9f9f9;
//             padding: 15px;
//             margin: 20px 0;
//             border-left: 4px solid #1a3b5d;
//             border-radius: 4px;
//         }
//         .documents ul {
//             margin: 10px 0 10px 20px;
//             padding: 0;
//         }
//         .documents li {
//             margin-bottom: 5px;
//         }
//         .signature {
//             margin-top: 30px;
//         }
//         .footer {
//             margin-top: 20px;
//             padding-top: 20px;
//             border-top: 1px solid #eeeeee;
//         }
//         .footer p {
//             margin: 5px 0;
//             color: #555555;
//         }
//         .team-name {
//             font-weight: bold;
//             color: #1a3b5d;
//             font-size: 16px;
//         }
//         .auto-note {
//             text-align: center;
//             margin-top: 20px;
//             font-size: 12px;
//             color: #999999;
//             font-style: italic;
//         }
//         @media only screen and (max-width: 480px) {
//             .container {
//                 width: 100%;
//                 padding: 10px;
//             }
//             .content {
//                 padding: 20px;
//             }
//             .documents {
//                 padding: 10px;
//             }
//         }
//     </style>
// </head>
// <body>
//     <div class='container'>
//         <div class='email-header'>
//             <h1 class='company-name'>Tribal Sand</h1>
//         </div>
//         <div class='content'>
//             <div class='greeting'>
//                 <p>Dear Travel Agent,</p>
//             </div>
//             <div class='message'>
//                 <p>Thank you for your interest in partnering with TribalSand. We're excited about the possibility of working together.</p>
//             </div>
//             <div class='documents'>
//                 <p>To proceed with your registration, please provide us with the following documents:</p>
//                 <ul>
//                     <li>Single Business License</li>
//                     <li>Certificate of Incorporation</li>
//                     <li>PIN Certificate</li>
//                 </ul>
//             </div>
//             <div class='message'>
//                 <p>Once we receive these documents, we will be happy to answer all your questions and discuss collaboration opportunities.</p>
//             </div>
//              <div class='message'>
//                 <p>Please send the above documents and any queries to reservations@tribalsand.com and federico@tribalsand.com.</p>
//             </div>
//             <div class='signature'>
//                 <p>Best regards,</p>
//                 <div class='footer'>
//                     <p class='team-name'>Team TribalSand</p>
//                 </div>
//             </div>
//         </div>
//         <div class='auto-note'>
//             <p>This is an automated message from our website.</p>
//         </div>
//     </div>
// </body>
// </html>
//     ";
//     $mail->send();

//     // echo json_encode(['success' => true]);
//     $responseMessage = '<div style="color:green;">Thank you! Your message has been sent successfully.</div>';
//     $hide = 1;

//     } catch (Exception $e) {
//         $responseMessage = '<div style="color:red;">Mailer Error: ' . $mail->ErrorInfo . '</div>';
//     }

// }
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TribalSand | Properties</title>
    <link rel="icon" href="images/favicon.png">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.1/font/bootstrap-icons.min.css"
        rel="stylesheet">
    <link rel="stylesheet" href="css/main.css">
    <?php //include 'includes/g-tag-head.php'; ?>
    <!-- IntlTelInput CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/css/intlTelInput.css">

    <style>
    .nav-dots {
        text-align: center;
        margin-top: 20px;
    }

    p {
        font-family: "Piazzolla", Sans-serif;
    }
    </style>

    <style>
    html,
    body {
        overflow-x: hidden;
        /* Prevent horizontal scrolling */
    }

    .row,
    .container-fluid {
        margin: 0 !important;
        padding: 0 !important;
    }

    img {
        max-width: 100%;
        height: auto;
        display: block;
    }



    .hero-section {
        position: relative;
        min-height: 100vh;
        background: linear-gradient(rgb(0, 0, 0, 0.8), rgba(0, 0, 0, 0.3)),
            url('images/zuri-hero.webp') center/cover no-repeat;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 0 20px;
    }

    .hero-section .hero-title {
        color: #FFFFFF;
        font-family: "Montserrat", Sans-serif;
        font-size: 4.5em;
        font-weight: 300;
        text-transform: uppercase;
        font-style: normal;
        letter-spacing: 6px;
    }

    .hero-section .hero-subtitle {
        color: #FFFFFF;
        font-family: "Montserrat", Sans-serif;
        font-size: 1.8em;
        font-weight: 200;
        text-transform: uppercase;
        font-style: normal;
        letter-spacing: 6px;
    }

    @media (max-width: 768px) {
        .hero-section .hero-title {
            font-size: 2rem;
        }

        .hero-section .hero-subtitle {
            font-size: 1.8rem;
        }
    }

    .banner-title {

        font-family: Marcellus SC, sans-serif;
        font-size: 40px;
        font-weight: 100;
        letter-spacing: 6px;
        line-height: 52px;
        text-decoration: none solid rgb(255, 255, 255);
        text-transform: uppercase;
        margin-bottom: 30px;
    }

    .banner-section {

        background-color: #2C3F58;

        color: white;

        padding: 100px 0;

        text-align: center;

        height: 750px;


    }



    .banner-title {

        font-family: Marcellus SC, sans-serif;

        font-size: 40px;

        font-weight: 100;

        letter-spacing: 6px;

        line-height: 52px;

        text-decoration: none solid rgb(255, 255, 255);

        text-transform: uppercase;

        margin-bottom: 30px;

    }



    .banner-description {

        font-family: Piazzolla, sans-serif;

        font-size: 18px;

        font-weight: 300;

        letter-spacing: 2px;

        line-height: 27px;

        text-decoration: none solid rgb(255, 255, 255);

        margin: 0 auto;

    }

    @media (max-width: 768px) {

        .banner-title {

            font-size: 2rem;

        }



        .banner-description {

            font-size: 1rem;

            padding: 0 20px;

        }

    }

    .img-wrapper {
        overflow: hidden;
    }

    .img-wrapper img {
        transition: transform 0.3s ease-in-out;
    }

    .img-wrapper:hover img {
        transform: scale(1.1);
    }

    .bg-image-overlay {
        position: relative;
        background: url('images/402898_278176578955483_2113923234_n.jpg') no-repeat center center;
        background-size: cover;
        border-radius: 1rem;
        overflow: hidden;
    }

    .bg-image-overlay::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        height: 100%;
        width: 100%;
        background-color: rgba(0, 2, 5, 0.3);
        /* Faint blue overlay */
        z-index: 1;
    }

    .bg-image-overlay .content {
        position: relative;
        z-index: 2;
    }
    </style>

</head>

<body>
    <?php //include 'includes/g-tag-body.php'; ?>

    <?php include 'includes/header.php'; ?>

    <section class="hero-section">
        <div class="container">
            <h1 class="hero-title">Find Your Dream Home</h1>
            <h4 class="hero-subtitle">Your perfect property is just a few clicks away.</h4>
        </div>
    </section>


    <!-- <h1 class="banner-title mx-auto wider-heading">Responsible Stewards of The Land</h1></br> -->

    <!-- <section class="banner-section py-5">
        <div class="container"> 
            <div class="row justify-content-center">
                <div class="col-12 col-md-10 col-lg-8">
                    <div class="text-center mb-4">
                        <img src="images/whitelogo.png" alt="Logo" class="img-fluid mx-auto"
                            style="max-height: 80px;" />
                    </div>
                    <div class="text-center">
                        <h1 class="banner-title mx-auto wider-heading fs-3 fs-md-1">Why buying a property with
                            Tribalsand?</h1>
                    </div>
                </div>
            </div> 
            <div class="row mt-5">
                <div class="col-12 col-md-4 mb-4">
                    <div class="card h-100 shadow-sm">
                        <img src="images/card-quality.jpg" class="card-img-top" alt="Quality">
                        <div class="card-body text-start">
                            <h5 class="card-title">Quality</h5>
                            <p class="card-text">
                                Our properties in Watamu are carefully selected through trusted agents with deep local
                                knowledge. We ensure every listing meets our standards for sustainability, location, and
                                investment value. Whether beachfront or inland, quality is never compromised.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4 mb-4">
                    <div class="card h-100 shadow-sm">
                        <img src="images/card-quality.jpg" class="card-img-top" alt="Support">
                        <div class="card-body text-start">
                            <h5 class="card-title">Support</h5>
                            <p class="card-text">
                                We guide you through every stage of the buying process—from property selection and legal
                                paperwork to final handover. Our team ensures you feel confident and informed at every
                                step, offering personalized support and clear communication throughout.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4 mb-4">
                    <div class="card h-100 shadow-sm">
                        <img src="images/card-quality.jpg" class="card-img-top" alt="Office">
                        <div class="card-body text-start">
                            <h5 class="card-title">Physical Office</h5>
                            <p class="card-text">
                                Visit us at our physical office inside the new Watamu Mall, where our team is always
                                ready to assist. We offer in-person consultations, property showcases, and a welcoming
                                environment to discuss your investment goals face-to-face.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section> -->

    <section class="py-5" style="margin-top:50px;">
        <div class="container">
            <div style="margin-bottom:20px;">
                <div class="row justify-content-center mb-5">
                    <div class="col-12 col-md-10 col-lg-8 text-center">

                        <h1 class="banner-title wider-heading">Why buying a property with Tribalsand?</h1>
                    </div>
                </div>
            </div>

            <div class="row row-cols-1 row-cols-md-3 g-4">
                <div class="col">
                    <div class="card h-100 p-3 shadow rounded">
                        <img src="images/card-quality.jpg" class="card-img-top zoom-img" alt="Quality">
                        <div class="card-body">
                            <h5 class="card-title">Quality</h5>
                            <p class="card-text">
                                Our properties in Watamu are carefully selected through trusted agents. We prioritize
                                prime locations, sustainability, and long-term value, ensuring a solid investment every
                                time.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="col">
                    <div class="card h-100 p-3 shadow rounded">
                        <img src="images/card-support.jpg" class="card-img-top" alt="Support">
                        <div class="card-body">
                            <h5 class="card-title">Support</h5>
                            <p class="card-text">
                                From start to finish, we provide full guidance on the property buying process. Our team
                                ensures transparency, clear documentation, and prompt assistance at every step.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="col">
                    <div class="card h-100 p-3 shadow rounded">
                        <img src="images/card-office.jpg" class="card-img-top" alt="Office">
                        <div class="card-body">
                            <h5 class="card-title">Fisical Office</h5>
                            <p class="card-text">
                                Visit us at our office in the new Watamu Mall for personalized consultations. We’re
                                available to answer questions, showcase listings, and walk you through the buying
                                journey in person.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>


    <section class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <h1 class="banner-title">Best Properties of the month </h1>
            </div>

            <div class="row row-cols-1 row-cols-md-4 g-4">
                <div class="col">
                    <div class="card" style="background-color:#f5f5f5">
                        <!-- <img src="images/zuri-3 (2).webp" class="card-img-top" alt="..."> -->
                        <div class="img-wrapper">
                            <img src="images/My-Amani-1.jpg" class="card-img-top" alt="Quality">
                        </div>
                        <div class="card-body">
                            <p class="card-text" style="font-size:1.3rem;">Golden Palm Imani For Sale | Ghepard
                                Exclusive in Watamu.</p>
                            <p class="card-text" style="font-size:1.3rem;">$100000</p>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="card" style="background-color:#f5f5f5">
                        <div class="img-wrapper">
                            <img src="images/My-Amani-5.jpg" class="card-img-top" alt="Quality">
                        </div>
                        <div class="card-body">
                            <p class="card-text" style="font-size:1.3rem;">Golden Palm Imani For Sale | Ghepard
                                Exclusive in Watamu.</p>
                            <p class="card-text" style="font-size:1.3rem;">$100000</p>
                        </div>
                    </div>
                </div>

                <div class="col">
                    <div class="card" style="background-color:#f5f5f5">
                        <div class="img-wrapper">
                            <img src="images/My-Amani-1.jpg" class="card-img-top" alt="Quality">
                        </div>
                        <div class="card-body">
                            <p class="card-text" style="font-size:1.3rem;">Golden Palm Imani For Sale | Ghepard
                                Exclusive in Watamu.</p>
                            <p class="card-text" style="font-size:1.3rem;">$100000</p>
                        </div>
                    </div>
                </div>

                <div class="col">
                    <div class="card" style="background-color:#f5f5f5">
                        <div class="img-wrapper">
                            <img src="images/My-Amani-5.jpg" class="card-img-top" alt="Quality">
                        </div>
                        <div class="card-body">
                            <p class="card-text" style="font-size:1.3rem;">Golden Palm Imani For Sale | Ghepard
                                Exclusive in Watamu.</p>
                            <p class="card-text" style="font-size:1.3rem;">$100000</p>
                        </div>
                    </div>
                </div>

                <div class="col">
                    <div class="card" style="background-color:#f5f5f5">
                        <div class="img-wrapper">
                            <img src="images/My-Amani-1.jpg" class="card-img-top" alt="Quality">
                        </div>
                        <div class="card-body">
                            <p class="card-text" style="font-size:1.3rem;">Golden Palm Imani For Sale | Ghepard
                                Exclusive in Watamu.</p>
                            <p class="card-text" style="font-size:1.3rem;">$100000</p>
                        </div>
                    </div>
                </div>

                <div class="col">
                    <div class="card" style="background-color:#f5f5f5">
                        <div class="img-wrapper">
                            <img src="images/My-Amani-5.jpg" class="card-img-top" alt="Quality">
                        </div>
                        <div class="card-body">
                            <p class="card-text" style="font-size:1.3rem;">Golden Palm Imani For Sale | Ghepard
                                Exclusive in Watamu.</p>
                            <p class="card-text" style="font-size:1.3rem;">$100000</p>
                        </div>
                    </div>
                </div>

                <div class="col">
                    <div class="card" style="background-color:#f5f5f5">
                        <div class="img-wrapper">
                            <img src="images/My-Amani-1.jpg" class="card-img-top" alt="Quality">
                        </div>
                        <div class="card-body">
                            <p class="card-text" style="font-size:1.3rem;">Golden Palm Imani For Sale | Ghepard
                                Exclusive in Watamu.</p>
                            <p class="card-text" style="font-size:1.3rem;">$100000</p>
                        </div>
                    </div>
                </div>

                <div class="col">
                    <div class="card" style="background-color:#f5f5f5">
                        <div class="img-wrapper">
                            <img src="images/My-Amani-5.jpg" class="card-img-top" alt="Quality">
                        </div>
                        <div class="card-body">
                            <p class="card-text" style="font-size:1.3rem;">Golden Palm Imani For Sale | Ghepard
                                Exclusive in Watamu.</p>
                            <p class="card-text" style="font-size:1.3rem;">$100000</p>
                        </div>
                    </div>
                </div>

                <div class="col">
                    <div class="card" style="background-color:#f5f5f5">
                        <div class="img-wrapper">
                            <img src="images/My-Amani-1.jpg" class="card-img-top" alt="Quality">
                        </div>
                        <div class="card-body">
                            <p class="card-text" style="font-size:1.3rem;">Golden Palm Imani For Sale | Ghepard
                                Exclusive in Watamu.</p>
                            <p class="card-text" style="font-size:1.3rem;">$100000</p>
                        </div>
                    </div>
                </div>

                <div class="col">
                    <div class="card" style="background-color:#f5f5f5">
                        <div class="img-wrapper">
                            <img src="images/My-Amani-5.jpg" class="card-img-top" alt="Quality">
                        </div>
                        <div class="card-body">
                            <p class="card-text" style="font-size:1.3rem;">Golden Palm Imani For Sale | Ghepard
                                Exclusive in Watamu.</p>
                            <p class="card-text" style="font-size:1.3rem;">$100000</p>
                        </div>
                    </div>
                </div>

                <div class="col">
                    <div class="card" style="background-color:#f5f5f5">
                        <div class="img-wrapper">
                            <img src="images/My-Amani-1.jpg" class="card-img-top" alt="Quality">
                        </div>
                        <div class="card-body">
                            <p class="card-text" style="font-size:1.3rem;">Golden Palm Imani For Sale | Ghepard
                                Exclusive in Watamu.</p>
                            <p class="card-text" style="font-size:1.3rem;">$100000</p>
                        </div>
                    </div>
                </div>

                <div class="col">
                    <div class="card" style="background-color:#f5f5f5">
                        <div class="img-wrapper">
                            <img src="images/My-Amani-5.jpg" class="card-img-top" alt="Quality">
                        </div>
                        <div class="card-body">
                            <p class="card-text" style="font-size:1.3rem;">Golden Palm Imani For Sale | Ghepard
                                Exclusive in Watamu.</p>
                            <p class="card-text" style="font-size:1.3rem;">$100000</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>


    <!-- <section class="py-5" style="background-color: #f8f9fa;">
        <div class="container">
            <div class="row bg-image-overlay shadow-sm p-4 px-5 align-items-center" style="height: 400px;">
                <div class="col-12 d-flex flex-wrap align-items-center content">
 
                    <div class="col-md-5 text-center text-md-start mb-3 mb-md-0">
                        <h1 class="fw-bold" style="color: orange;">Professionalism </h1>
                        <h2 class="fw-bold text-white">at our core.</h2>
                        <button class="btn btn-light rounded-pill mt-3 px-4">CONTACT US</button>
                    </div> 
                    <div class="col-md-7 text-white">
                        <p class="mb-0" style="font-size:25px">
                            At Tribalsand, we uphold the highest standards of service. Our experienced team ensures
                            transparency, ethical practices, and personalized assistance at every step of your property
                            journey. We aim to build trust and long-lasting relationships with our clients.
                        </p>
                    </div>

                </div>
            </div>
        </div>
    </section> -->

    <section class="py-5" style="background-color: #f8f9fa;">
        <div class="container">
            <div class="row shadow-sm p-4 p-md-5 align-items-center" style="
      min-height: 400px;
      background: linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.5)), url('images/402898_278176578955483_2113923234_n.jpg') center center / cover no-repeat;
      border-radius: 10px;">

                <div class="col-12 d-flex flex-column flex-md-row align-items-center justify-content-between gap-4 p-5">

                    <!-- Left Column -->
                    <div class="col-12 col-md-5 text-center text-md-start">
                        <h1 class="fw-bold" style="color: #ffa500; text-shadow: 0 0 8px rgba(0, 0, 0, 0.8);">
                            Professionalism</h1>
                        <h2 class="fw-bold text-white" style="text-shadow: 0 0 8px rgba(0, 0, 0, 0.8);">at our core.
                        </h2>
                        <button class="btn btn-light rounded-pill mt-3 px-4">CONTACT US</button>
                    </div>

                    <!-- Right Column -->
                    <div class="col-12 col-md-7 text-white text-center text-md-start">
                        <p class="mb-0 fs-5" style="text-shadow: 0 0 8px rgba(0, 0, 0, 0.8);">
                            At Tribalsand, we uphold the highest standards of service. Our experienced team ensures
                            transparency,
                            ethical practices, and personalized assistance at every step of your property journey. We
                            aim to build
                            trust and long-lasting relationships with our clients.
                        </p>
                    </div>

                </div>
            </div>
        </div>
    </section>



    <!-- <section class="py-5">
        <div class="container">
            
            <div class="row justify-content-center mb-5">
                <div class="col-12 col-md-10 col-lg-8 text-center">
                    <h1 class="banner-title wider-heading text-bold">What We Have to Offer</h1>
                    <p style="font-size:20px;">We specialize in the finest properties, including private villas,
                        residential apartments, and breathtaking plots.</p>
                </div>
            </div>
 
            <div class="row" style="height:400px;">

                <div class="col-md-4 mb-4">
                    <div class="card h-100 border-0" style="background-image: 
                     linear-gradient(to bottom, rgb(129, 127, 127), rgba(75, 74, 74, 0.2)), 
                        url('images/zuri-3 (2).webp'); background-size: cover; background-position: center;">
                        <p class="card-title text-white p-3">BUY FOR COMMERCIAL USE</p>
                        <p class="card-title text-white p-3" style="font-size:1.5rem">BUY A COMMERCIAL <br> PROPERTY</p>
                        <p class="card-title text-white p-3">Explore from Office Spaces, Co-working spaces, Retail
                            Shops, Land, Factories, and more</p>
                    </div>
                </div>

                <div class="col-md-4 mb-4">
                    <div class="card h-100 border-0" style="background-image: 
                     linear-gradient(to bottom, rgb(129, 127, 127), rgba(75, 74, 74, 0.2)), 
                        url('images/zuri-3 (2).webp'); background-size: cover; background-position: center;">
                        <p class="card-title text-white p-3">BUY FOR COMMERCIAL USE</p>
                        <p class="card-title text-white p-3" style="font-size:1.5rem">BUY A COMMERCIAL <br> PROPERTY</p>
                        <p class="card-title text-white p-3">Explore from Office Spaces, Co-working spaces, Retail
                            Shops, Land, Factories, and more</p>
                    </div>
                </div>

                <div class="col-md-4 mb-4">
                    <div class="card h-100 border-0" style="background-image: 
                     linear-gradient(to bottom, rgb(129, 127, 127), rgba(75, 74, 74, 0.2)), 
                        url('images/zuri-3 (2).webp'); background-size: cover; background-position: center;">
                        <p class="card-title text-white p-3">BUY FOR COMMERCIAL USE</p>
                        <p class="card-title text-white p-3" style="font-size:1.5rem">BUY A COMMERCIAL <br> PROPERTY</p>
                        <p class="card-title text-white p-3">Explore from Office Spaces, Co-working spaces, Retail
                            Shops, Land, Factories, and more</p>
                    </div>
                </div>
            </div>
        </div>
    </section> -->


    <section class="py-5">
        <!-- Added mb-5 for spacing below -->
        <div class="container">

            <div class="row justify-content-center mb-5">
                <div class="col-12 col-md-10 col-lg-8 text-center">
                    <h1 class="banner-title wider-heading text-bold">What We Have to Offer</h1>
                    <p style="font-size:20px;">We specialize in the finest properties, including private villas,
                        residential apartments, and breathtaking plots.</p>
                </div>
            </div>

            <div class="row g-4">
                <!-- g-4 for spacing between columns -->
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="card h-100 border-0" style="background-image: 
                     linear-gradient(to bottom, rgb(129, 127, 127), rgba(75, 74, 74, 0.2)), 
                        url('images/zuri-3 (2).webp'); background-size: cover; background-position: center;">
                        <p class="card-title text-white p-3">BUY FOR COMMERCIAL USE</p>
                        <p class="card-title text-white p-3" style="font-size:1.5rem">BUY A COMMERCIAL <br> PROPERTY</p>
                        <p class="card-title text-white p-3">Explore from Office Spaces, Co-working spaces, Retail
                            Shops, Land, Factories, and more</p>
                    </div>
                </div>

                <!-- Card 2 -->
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="card h-100 border-0" style="background-image: 
                     linear-gradient(to bottom, rgb(129, 127, 127), rgba(75, 74, 74, 0.2)), 
                        url('images/zuri-3 (2).webp'); background-size: cover; background-position: center;">
                        <p class="card-title text-white p-3">BUY FOR COMMERCIAL USE</p>
                        <p class="card-title text-white p-3" style="font-size:1.5rem">BUY A COMMERCIAL <br> PROPERTY</p>
                        <p class="card-title text-white p-3">Explore from Office Spaces, Co-working spaces, Retail
                            Shops, Land, Factories, and more</p>
                    </div>
                </div>

                <!-- Card 3 -->
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="card h-100 border-0" style="background-image: 
                        linear-gradient(to bottom, rgb(129, 127, 127), rgba(75, 74, 74, 0.2)), 
                        url('images/zuri-3 (2).webp'); background-size: cover; background-position: center;">
                        <p class="card-title text-white p-3">BUY FOR COMMERCIAL USE</p>
                        <p class="card-title text-white p-3" style="font-size:1.5rem">BUY A COMMERCIAL <br> PROPERTY</p>
                        <p class="card-title text-white p-3">Explore from Office Spaces, Co-working spaces, Retail
                            Shops, Land, Factories, and more</p>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- <section class="py-5">
        <div class="container">
            <div class="row align-items-center"> 
                <div class="col-md-6 mb-4 mb-md-0 d-flex flex-column justify-content-center h-100">
                    <div> 
                        <h2 class="card-title text-dark p-3">How can we help you?</h2></br></br>
                        <p class="card-title text-dark p-3">If you have any questions or would like to know more about
                            our services or properties, please feel free to contact us.</p>
                        <p class="card-title text-dark p-3"><strong>Phone:</strong> +254 740 379694</p>
                        <p class="card-title text-dark p-3"><strong>Email:</strong> agent01@baharinihomes.com</p>
                        <p class="card-title text-dark p-3"><strong>Office:</strong> Watamu Mall, Watamu</p>
                    </div>
                </div> 
                <div class="col-md-6">
                    <div class="p-4 bg-light rounded shadow" style="background-color:black">
                        <form>
                            <div class="mb-3">
                                <label for="name" class="form-label">Your Name</label>
                                <input type="text" class="form-control" id="name" placeholder="Enter your name">
                            </div>

                            <div class="mb-3">
                                <label for="email" class="form-label">Your Email</label>
                                <input type="email" class="form-control" id="email" placeholder="Enter your email">
                            </div>

                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="text" class="form-control" id="phone"
                                    placeholder="Enter your phone number">
                            </div>

                            <div class="mb-3">
                                <label for="message" class="form-label">Your Message</label>
                                <textarea class="form-control" id="message" rows="4"
                                    placeholder="Enter your message"></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">Send Message</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section> -->

    <section class="py-5 mt-5">
        <div class="container">
            <div class="row g-5 align-items-start">

                <div class="col-12 col-lg-7 text-center text-lg-start" style="padding-top:100px;">
                    <!-- <h2 class="fw-bold mb-3">How can we help you?</h2> -->
                    <h4 class="banner-title wider-heading text-bold">How can we help you?</h4>
                    <p class="mb-4">If you have any questions or would like to know more about our services or
                        properties, please feel free to contact us.</p>
                    <p><strong>Phone:</strong> +254 999 999999</p>
                    <p><strong>Email:</strong> agent01@tribalsand.com</p>
                    <p><strong>Office:</strong> Watamu, Watamu</p>
                    <div class="social-icons mt-3">
                        <a href="#"><i class="bi bi-facebook me-3 fs-4 text-dark"></i></a>
                        <a href="#"><i class="bi bi-instagram me-3 fs-4 text-dark"></i></a>
                        <a href="#"><i class="bi bi-whatsapp fs-4 text-dark"></i></a>
                    </div>
                </div>

                <div class="col-12 col-lg-5 d-flex justify-content-center">
                    <div class="bg-dark text-white p-4 rounded-4" style="width: 100%; max-width: 500px;">
                        <h4 class="fw-bold mb-4 text-center">Get in Touch</h4>
                        
                        <div class="alert alert-success <?php echo (isset($hide)) ? '' : 'd-none'; ?>" id="successAlert" role="alert"> 
                            <p id="responseMessage"><?php echo $responseMessage ?></p> 
                        </div>

                        <!-- <form id="agent-signup-form" name="sform" method="POST" action="TRIBAL_SAND/proporties.php"> -->
                        <form method="POST" action="">
                            <div class="row">
                                <div class="col-12 col-md-6 mb-3">
                                    <label for="name" class="form-label">Name:</label>
                                    <input type="text" class="form-control text-white bg-transparent border-white"
                                        id="name" name="Ename" value="" required>
                                </div>
                                <div class="col-12 col-md-6 mb-3">
                                    <label for="phone" class="form-label">Phone</label>
                                    <input type="text" class="form-control text-white bg-transparent border-white"
                                        id="phone" name="phone" value="" required>
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" class="form-control text-white bg-transparent border-white"
                                        id="email" name="email" value="" required>
                                </div>
                                <div class="col-12 mb-4">
                                    <label for="message" class="form-label">Message</label>
                                    <textarea class="form-control text-white bg-transparent border-white" id="message"
                                        rows="4" name="message" value="" required></textarea>
                                </div>
                            </div>
                             
                            <div class="text-center">
                                <div class="text-center">
                                    <input type="submit" value="Submit"
                                        class="submit-btn btn btn-info text-white fw-semibold px-4 py-2" id="submitBtn">
                                    <span class="spinner-border spinner-border-sm ms-2 d-none" role="status"
                                        id="submitSpinner">
                                        <span class="visually-hidden">Loading...</span>
                                    </span>
                                </div>
                            </div>

                            <!-- <button type="submit" class="submit-btn btn btn-info text-white fw-semibold px-4 py-2" style="padding: 10px 20px;">Send</button> -->
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <?php include 'includes/footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>

    <!-- IntlTelInput Script -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/intlTelInput.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>



// document.addEventListener('DOMContentLoaded', function () {
//     const form = document.getElementById('signupform');

//     form.addEventListener('submit', function (e) {
//         e.preventDefault(); // stop default form submission

//         const submitBtn = document.getElementById('submitBtn');
//         const spinner = document.getElementById('submitSpinner');
//         const successAlert = document.getElementById('successAlert');

//         submitBtn.disabled = true;
//         submitBtn.innerHTML = 'Sending... <span class="spinner-border spinner-border-sm ms-2" role="status"></span>';

//         const formData = new FormData(form);
//         // console.log('FormData is working');
//         // console.log([...formData.entries()]); // Show actual values
//         // return false;

//         fetch('TRIBAL_SAND/processproperties.php', {
//             method: 'POST',
//             body: formData
//         })
//         .then(response => response.json())
//         .then(data => {
//             if (data.success) {
//                 form.style.display = 'none';
//                 successAlert.classList.remove('d-none');
//             } else {
//                 throw new Error(data.message || 'Something went wrong');
//             }
//         })
//         .catch(error => {
//             alert('Error: ' + error.message);
//         })
//         .finally(() => {
//             submitBtn.disabled = false;
//             submitBtn.innerHTML = 'Submit';
//         });
//     });
// });





        // document.getElementById('signupform').addEventListener('submit', function(e) {

        //     e.preventDefault(); 
        //     const form = this; 
        //     const submitBtn = document.getElementById('submitBtn'); 
        //     const spinner = document.getElementById('submitSpinner'); 
        //     const successAlert = document.getElementById('successAlert');
   
        //     submitBtn.disabled = true; 
        //     submitBtn.innerHTML = 'Sending... <span class="spinner-border spinner-border-sm ms-2" role="status"></span>';
 
        //     const formData = new FormData(form);
        //     console.log(formData);
        //     return false;
        //     fetch('TRIBAL_SAND/processproperties.php', {

        //         method: 'POST',

        //         body: formData

        //     })

        //     .then(response => response.json())

        //     .then(data => {

        //         if (data.success) {

        //             form.style.display = 'none';

        //             successAlert.classList.remove('d-none');

        //         } else {

        //             throw new Error(data.message || 'Something went wrong');

        //         }

        //     })

        //     .catch(error => {

        //         alert('Error: ' + error.message);

        //     })

        //     .finally(() => {

        //         // Reset button state

        //         submitBtn.disabled = false;

        //         submitBtn.innerHTML = 'Submit';

        //     });

        // });

    </script>












    <script>
        
    // document.getElementById('agent-signup-form').addEventListener('submit', function(e) {
    //     e.preventDefault();

    //     const form = this;
    //     const submitBtn = document.getElementById('submitBtn');
    //     const spinner = document.getElementById('submitSpinner');
    //     const successAlert = document.getElementById('successAlert');

    //     submitBtn.disabled = true;
    //     submitBtn.value = 'Sending...';
    //     spinner.classList.remove('d-none');

    //     const formData = new FormData(form);
    //     formData.append('ajax', '1'); // To detect AJAX request

    //     fetch('', {
    //             method: 'POST',
    //             body: formData
    //         })
    //         .then(response => response.json())
    //         .then(text => {
    //                 console.log('Raw Response:', text); // 👈 This shows what you're actually getting
    //                 try {
    //                     const data = JSON.parse(text);
    //                     if (data.success) {
    //                         form.reset();
    //                         // form.style.display = 'none';
    //                         // successAlert.classList.remove('d-none');
    //                     } else {
    //                         alert('Submission failed: ' + data.message);
    //                     }
    //                 } catch (e) {
    //                     console.error('JSON Parse Error:', e.message);
    //                     alert('Invalid JSON response from server. See console.');
    //                 }
    //         })
    //         .catch(error => {
    //             alert('Error: ' + error.message);
    //         })
    //         .finally(() => {
    //             submitBtn.disabled = false;
    //             submitBtn.value = 'Submit';
    //             spinner.classList.add('d-none');
    //         });
    // });




    // $('#agent-signup-form').on('submit', function (e) {
    //     e.preventDefault();

    //     const form = $(this);
    //     const submitBtn = $('#submitBtn');
    //     const spinner = $('#submitSpinner');
    //     const successAlert = $('#successAlert');

    //     submitBtn.prop('disabled', true);
    //     spinner.removeClass('d-none');
    //     submitBtn.contents().first()[0].textContent = 'Sending';

    //     // console.log(form.serialize());

    //     // return false;

    //     $.ajax({
    //         url: 'TRIBAL_SAND/process-properties-form.php',
    //         method: 'POST',
    //         data: form.serialize(),
    //         dataType: 'json',
    //         success: function (response) {
    //             if (response.success) {
    //                 form.hide();
    //                 successAlert.removeClass('d-none');
    //             } else {
    //                 alert('Error: ' + response.message);
    //             }
    //         },
    //         error: function (xhr, status, error) {
    //             alert('AJAX Error: ' + error);
    //         },
    //         complete: function () {
    //             submitBtn.prop('disabled', false);
    //             spinner.addClass('d-none');
    //             submitBtn.contents().first()[0].textContent = 'Submit';
    //         }
    //     });
    // });


    // document.getElementById('agent-signup-form').addEventListener('submit', function(e) {
    //     e.preventDefault();

    //     const form = this;
    //     const submitBtn = document.getElementById('submitBtn');
    //     const spinner = document.getElementById('submitSpinner');
    //     const successAlert = document.getElementById('successAlert');

    //     // Show spinner
    //     submitBtn.disabled = true;
    //     spinner.classList.remove('d-none');
    //     submitBtn.value = 'Sending...';

    //     const formData = new FormData(form);

    //     fetch('process-properties-form.php', {
    //         method: 'POST',
    //         body: formData
    //     })
    //     .then(response => response.json())
    //     .then(data => {
    //         if (data.success) {
    //             form.reset();
    //             form.style.display = 'none';
    //             successAlert.classList.remove('d-none');
    //         } else {
    //             alert(data.message || 'Something went wrong');
    //         }
    //     })
    //     .catch(error => {
    //         alert('Error: ' + error.message);
    //     })
    //     .finally(() => {
    //         submitBtn.disabled = false;
    //         submitBtn.value = 'Submit';
    //         spinner.classList.add('d-none');
    //     });
    // });


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