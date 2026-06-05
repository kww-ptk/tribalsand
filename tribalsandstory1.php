<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title> TribalSand</title>

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
    p {
        font-family: "Piazzolla", Sans-serif;
    }
    </style>

    <style>
    .hero-section {
        min-height: 100vh;
        overflow: hidden;
        font-family: "Montserrat", Sans-serif;
        /* font-size: 4.5em; */
        font-weight: 300;
        text-transform: uppercase;
        font-style: normal;
        letter-spacing: 4px;
    }

    .object-fit-cover {
        object-fit: cover;
    }


    /* .hero-section {
            position: relative;
            min-height: 100vh;
            background: linear-gradient(rgba(0, 0, 0, 0.0), rgba(0, 0, 0, 0.0)),
                url('images/New-hero-banner.jpg') center/cover no-repeat;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 0 20px;
        } */

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
        font-size: 2.8em;
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
    </style>


    <style>
    .book-now-section {
        background-color: #031B31;
        padding-bottom: 4rem;
        font-family: "Piazzolla", Sans-serif;
    }

    .book-now-section .booking-container {
        background: white;
        border-radius: 15px;
        padding: 2rem;
        box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
        margin: 2rem auto 0rem auto;
        max-width: 1200px;
    }

    .book-now-section .page-title {
        text-align: center;
        color: white;
        font-size: 2.5rem;
        letter-spacing: 4px;
        padding: 2rem 0;
    }


    .book-now-section .form-control,
    .book-now-section .form-select {
        border: 1px solid #ddd;
        /* padding: 0.75rem; */
    }

    .book-now-section .calendar-icon {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: #666;
    }

    .book-now-section .date-input-container {
        position: relative;
    }

    .book-now-section .send-inquiry-btn {
        background-color: #c9a96a;
        border: none;
        color: white;
        padding: 0.75rem;
        width: 100%;
    }

    .book-now-section .send-inquiry-btn:hover {
        background-color: #B39565;
    }

    .book-now-section .flag-select {
        width: 100px;
    }




    .book-now-section .error-message {
        color: #dc3545;
        font-size: 14px;
        margin-top: 5px;
        display: none;
    }

    /* Override some IntlTelInput styles for better integration */
    .book-now-section .iti {
        width: 100%;

    }

    .book-now-section .iti__flag {
        background-image: url("https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/img/flags.png");
    }

    @media (-webkit-min-device-pixel-ratio: 2),
    (min-resolution: 192dpi) {
        .book-now-section .iti__flag {
            background-image: url("https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/img/flags@2x.png");
        }
    }

    .book-now-section .valid-feedback {
        color: #28a745;
        display: none;
        font-size: 14px;
        margin-top: 5px;
    }

    /* .phone-input-container{
            overflow-x: hidden;
        } */
    </style>


    <style>
    .LuxuriousBeach {
        background-color: #FFFAEF;
    }

    .LuxuriousBeach .row {
        height: 100%;
        color: white;
    }

    .LuxuriousBeach .hero-section2 {
        background-color: #031B31;
    }

    .LuxuriousBeach .content-wrapper {
        padding: 2rem;
        display: flex;
        flex-direction: column;
        justify-content: center;
        height: 100%;
    }

    .LuxuriousBeach .main-title {
        font-size: 2.5rem;
        letter-spacing: 2px;
        color: #FFFFFF;
        margin-bottom: 0.5rem;
        font-weight: 200;
        text-transform: uppercase;
        letter-spacing: 4px;
    }

    .LuxuriousBeach .main-title::after {
        content: "";
        display: block;
        width: 25%;
        /* Set the width of the border to 25% */
        margin-top: 1.5rem;
        /* Center the border */
        border-bottom: 2px solid #c9a96a;
        /* Match the color and thickness */
    }

    .LuxuriousBeach .description {
        color: #FFFFFF;
        margin-bottom: 1rem;
        font-weight: 300;
        letter-spacing: 2px;
    }

    .LuxuriousBeach .book-btn {
        background-color: #C5A572;
        color: white;
        padding: 0.8rem 2rem;
        border: none;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-block;
    }

    .LuxuriousBeach .book-btn:hover {
        background-color: #B39362;
        transform: scale(0.9);
        color: white;
    }

    .LuxuriousBeach .slider-container {
        height: 100%;
        position: relative;
        overflow: hidden;
    }

    .LuxuriousBeach .slider-image {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        transition: opacity 1s ease-in-out;
        object-fit: cover;
    }

    .LuxuriousBeach .slider-image.active {
        opacity: 1;
    }

    @media (max-width: 768px) {
        .LuxuriousBeach .hero-section2 {
            min-height: auto;
        }

        .LuxuriousBeach .slider-container {
            height: 100%;
        }

        .LuxuriousBeach .main-title {
            font-size: 2rem;
        }
    }
    </style>

    <style>
    .amenities-section {
        background: linear-gradient(#081323DE, #081323DE),
            url('images/IMG_2036.jpg');
        background-size: cover;
        background-position: center;
        padding: 40px 0;
        color: white;
    }

    .amenities-section .section-title {
        margin-bottom: 40px;
        position: relative;
        font-weight: 300;
        text-transform: uppercase;
        font-style: normal;
        letter-spacing: 4px;
        font-size: 30px;
    }

    .amenities-section .section-title:after {
        content: '';
        display: block;
        width: 80px;
        height: 2px;
        background: #c8a97e;
        margin-top: 15px;
    }

    .amenities-section .amenity-item {
        margin-bottom: 30px;
        display: flex;
        align-items: center;
        gap: 15px;
        font-family: "Piazzolla", Sans-serif;
    }

    .amenities-section .amenity-icon {
        font-size: 24px;
        color: #c8a97e;
        width: 40px;
        text-align: center;
    }

    .amenities-section .amenity-text {
        flex: 1;
    }

    .amenities-section .amenity-text h5 {
        margin-bottom: 0px;
        font-size: 16px;
        font-weight: 500;
    }

    .amenities-section .book-btn {
        background-color: #C5A572;
        color: white;
        padding: 0.8rem 2rem;
        border: none;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-block;
    }

    .amenities-section .book-btn:hover {
        background-color: #B39362;
        transform: scale(0.9);
        color: white;
    }

    .amenities-section .intro-text {
        margin-bottom: 3rem;
        text-align: center;
        font-weight: 300;
        line-height: 1.8;
    }
    </style>


    <style>
    .premiumAmenities {
        background-color: #031B31;

    }

    .premiumAmenities .section-title {
        color: white;
    }

    .premiumAmenities .section-title::after {
        content: "";
        display: block;
        width: 25%;
        /* Set the width of the border to 25% */
        margin-top: 1.5rem;
        /* Center the border */
        border-bottom: 2px solid #c9a96a;
        /* Match the color and thickness */
    }

    .premiumAmenities p {
        color: white;
    }

    .premiumAmenities ul {
        color: white;
        font-family: "Piazzolla", Sans-serif;
    }

    .premiumAmenities h1,
    .premiumAmenities h5 {
        font-family: "Piazzolla", Sans-serif;
    }
    </style>

    <style>
    .slider-container {
        margin: 0 auto;
        padding: 40px 20px;
        position: relative;
        font-family: "Piazzolla", Sans-serif;
    }

    .slide {
        display: none;
        text-align: center;
        animation: fadeEffect 1s;
        background-color: #fdf6e9;
        color: black;
    }

    @keyframes fadeEffect {
        from {
            opacity: 0.4
        }

        to {
            opacity: 1
        }
    }

    .active {
        display: block;
    }

    .quote {
        font-style: italic;
        font-size: 1.2em;
        color: #333;
        margin-bottom: 30px;
        line-height: 1.6;
        color: black !important;
        padding-top: 20px;
    }

    .profile {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }

    .profile img {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        object-fit: cover;
    }

    .profile-name {
        font-weight: 500;
        color: #333;
    }

    .nav-dots {
        text-align: center;
        margin-top: 20px;
    }

    .dot {
        height: 10px;
        width: 10px;
        margin: 0 5px;
        background-color: #333;
        border-radius: 50%;
        display: inline-block;
        cursor: pointer;
        transition: background-color 0.3s ease;
    }

    .dot.active {
        background-color: #bbb;
    }
    </style>

    <!-- Slider CSS -->
    <style>
    /* General Styles */
    .slidbox {
        margin: 0;
        font-family: Arial, sans-serif;
        background-color: #f9f9f9;
    }

    .tourist-activities-section {
        /* max-width: 1400px; */
        margin: 0 auto;
        padding: 20px;
        background-color: #e6dac2;
        overflow: hidden;
    }

    .activities-carousel {
        position: relative;
        overflow: hidden;
    }

    .activities-track {
        display: flex;
        transition: transform 0.5s ease;
        will-change: transform;
    }

    .activity-slide {
        flex: 0 0 33.333%;
        padding: 0 5px;
        box-sizing: border-box;
    }

    .activity-card {
        position: relative;
        height: 550px;
        border-radius: 30px;
        overflow: hidden;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .activity-card:hover {
        /* transform: translateY(-5px); */
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
    }

    .activity-card img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.5s ease;
    }

    .activity-card:hover img {
        transform: scale(1.05);
    }

    .activity-overlay {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        padding: 40px;
        background: linear-gradient(to top,
                rgba(0, 0, 0, 0.8) 0%,
                rgba(0, 0, 0, 0.6) 40%,
                rgba(0, 0, 0, 0) 100%);
        color: white;
        opacity: 0.9;
        transition: opacity 0.3s ease;
    }

    .activity-title {
        font-size: 24px;
        font-weight: 600;
        line-height: 36px;
        font-style: italic;
        margin-bottom: 10px;
    }

    .activity-description {
        font-size: 15px;
        font-style: italic;
        line-height: 22.5px;
    }

    .carousel-button {
        position: absolute;
        /* top: 50%; */
        transform: translateY(-740%);
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background-color: rgba(0, 0, 0, 0.5);
        border: none;
        color: white;
        cursor: pointer;
        z-index: 10;
        transition: background-color 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
    }

    .carousel-button:hover {
        background-color: rgba(0, 0, 0, 0.8);
    }

    .carousel-button-prev {
        left: 2px;
    }

    .carousel-button-next {
        right: 2px;
    }

    .section-heading {
        font-family: Montserrat, sans-serif;
        font-size: 25px;
        font-weight: 200;
        line-height: 25px;
        text-transform: uppercase;
        text-align: center;
        margin: 20px 0;
    }

    @media (max-width: 1024px) and (min-width: 769px) {
        .activity-slide {
            flex: 0 0 50%;
        }
    }

    @media (max-width: 768px) {
        .activity-slide {
            flex: 0 0 100%;
        }

        .activity-card {
            height: 400px;
        }

        .activity-overlay {
            padding: 20px;
        }

        .activity-title {
            font-size: 1.2rem;
        }

        .activity-description {
            font-size: 0.9rem;
        }

        .carousel-button {
            width: 30px;
            height: 30px;
            font-size: 16px;
        }
    }
    </style>

    <!-- adventure section and footer css -->
    <style>
    /* Adventure Section Styles */
    .adventure-section {
        background-image: url('images/footer-banner.jpg');
        background-size: cover;
        background-position: center;
        position: relative;
        padding: 150px 0;
        color: white;
    }

    .adventure-section::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1;
    }

    .adventure-section .adventure-content {
        position: relative;
        z-index: 2;
        text-align: center;
        max-width: 600px;
        margin: 0 auto;
        font-family: "Piazzolla", Sans-serif;
    }

    .adventure-section .adventure-title {
        font-family: montserrat, sans-serif;
        font-size: 36px;
        font-weight: 200;
        letter-spacing: 6px;
        line-height: 46.8px;
        text-decoration: none solid rgb(255, 255, 255);
        text-align: center;
        text-transform: uppercase;
        word-spacing: 0;
        margin-bottom: 20px;
    }

    .adventure-section .adventure-subtitle {
        font-family: "Piazzolla", Sans-serif;
        font-size: 16px;
        font-weight: 300;
        letter-spacing: 2px;
        line-height: 22.4px;
        text-decoration: none solid rgb(255, 255, 255);
        text-align: center;
        word-spacing: 0;
        margin-bottom: 20px;
    }

    .adventure-section .btn-reservation {
        background-color: #C9a96a;
        color: white;
        padding: 16px 48px 16px 48px;
        width: 435px;
        text-decoration: none;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        font-family: "Montserrat Alternates", sans-serif;
        font-size: 20px;
        font-weight: 300;
        letter-spacing: 2px;
        line-height: 20px;
        text-decoration: none solid rgb(255, 255, 255);
        text-align: center;
        word-spacing: 0;
    }

    .adventure-section .btn-reservation:hover {
        transform: scale(0.95);
        /* background-color: #45a049; */
        color: white;
    }

    .adventure-section .btn-reservation i {
        transition: transform 0.3s ease;
    }

    .adventure-section .btn-reservation:hover i {
        transform: translateX(5px);
    }


    /* Mobile-specific styles */
    @media screen and (max-width: 480px) {

        .adventure-section .btn-reservation {
            width: 100%;
            padding: 12px 0;
        }

        .adventure-section .adventure-title {
            font-size: 28px;
        }

        .adventure-section .adventure-subtitle {
            font-size: 14px;
        }
    }

    /* All Screens Media Query (Responsive for all screen sizes) */
    @media screen and (max-width: 1024px) {
        .adventure-section .adventure-title {
            font-size: 32px;
        }

        .adventure-section .adventure-subtitle {
            font-size: 14px;
        }

        .adventure-section .btn-reservation {
            width: 100%;
        }

    }
    </style>


    <style>
    /* Custom Navigation Styles */
    .nav-tabs {
        border: none;
        justify-content: center;
        margin-bottom: 2rem;
    }

    .nav-tabs .nav-link {
        border: none;
        color: #666;
        font-size: 1.1rem;
        padding: 1rem 1.5rem;
        margin: 0 0.5rem;
        position: relative;
        transition: all 0.3s ease;
    }

    .nav-tabs .nav-link:hover {
        color: #333;
        background: none;
    }

    .nav-tabs .nav-link.active {
        color: #0d6efd;
        background: none;
    }

    .nav-tabs .nav-link::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        height: 2px;
        background-color: #0d6efd;
        transform: scaleX(0);
        transition: transform 0.3s ease;
    }

    .nav-tabs .nav-link.active::after {
        transform: scaleX(1);
    }

    /* Room Type Navigation */
    .room-types {
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
        gap: 1rem;
        margin-bottom: 3rem;
        padding: 0;
        list-style: none;
    }

    .room-type-btn {
        background: none;
        border: none;
        color: #666;
        padding: 0.5rem 1rem;
        position: relative;
        transition: all 0.3s ease;
    }

    .room-type-btn:hover {
        color: #333;
    }

    .room-type-btn.active {
        color: #0d6efd;
    }

    .room-type-btn::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        height: 2px;
        background-color: #0d6efd;
        transform: scaleX(0);
        transition: transform 0.3s ease;
    }

    .room-type-btn.active::after {
        transform: scaleX(1);
    }

    /* Gallery Grid */
    .gallery-item {
        position: relative;
        border-radius: 8px;
        overflow: hidden;
        cursor: pointer;
        margin-bottom: 1.5rem;
    }

    .gallery-item img {
        width: 100%;
        height: 300px;
        object-fit: cover;
        transition: transform 0.5s ease;
    }

    .gallery-item:hover img {
        transform: scale(1.05);
    }

    .gallery-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.3);
        opacity: 0;
        transition: opacity 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .gallery-item:hover .gallery-overlay {
        opacity: 1;
    }

    .gallery-overlay i {
        color: white;
        font-size: 2rem;
    }

    /* Modal Styles */
    .modal-fullscreen {
        background: rgba(0, 0, 0, 0.9);
    }

    .modal-fullscreen .modal-content {
        background: none;
        border: none;
    }

    .modal-image {
        max-height: 85vh;
        object-fit: contain;
        border-radius: 8px;
    }

    .modal-nav-btn {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        background: rgba(255, 255, 255, 0.1);
        border: none;
        color: white;
        width: 50px;
        height: 50px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.3s ease;
    }

    .modal-nav-btn:hover {
        background: rgba(255, 255, 255, 0.2);
    }

    .modal-prev {
        left: 20px;
    }

    .modal-next {
        right: 20px;
    }

    .modal-close {
        position: absolute;
        top: 20px;
        right: 20px;
        color: white;
        background: none;
        border: none;
        font-size: 2rem;
        z-index: 1050;
        transition: color 0.3s ease;
    }

    .modal-close:hover {
        color: #0d6efd;
    }

    .image-caption {
        color: white;
        text-align: center;
        margin-top: 1rem;
    }

    /* Animation Classes */
    .fade-in {
        animation: fadeIn 0.5s ease-in;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Responsive Adjustments */
    @media (max-width: 768px) {
        .nav-tabs .nav-link {
            padding: 0.75rem 1rem;
            font-size: 1rem;
        }

        .gallery-item img {
            height: 250px;
        }

        .modal-nav-btn {
            width: 40px;
            height: 40px;
        }
    }
    </style>

    <style>
    .loader-container {
        text-align: center;
        padding: 20px;
        display: none;
    }

    .loader {
        display: inline-block;
        width: 50px;
        height: 50px;
        border: 3px solid #f3f3f3;
        border-radius: 50%;
        border-top: 3px solid #0d6efd;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }
    </style>




</head>

<body>
    <?php include 'includes/g-tag-body.php'; ?>

    <?php include 'includes/header.php'; ?>

    <section
        class="hero-section position-relative d-flex align-items-center justify-content-center text-center text-white">

        <!-- Background Video -->
        <video autoplay muted loop playsinline class="position-absolute top-0 start-0 w-100 h-100 object-fit-cover">
            <source src="images/Dji 0949.mp4" type="video/mp4">
        </video>

        <!-- Dark Overlay -->
        <div class="position-absolute top-0 start-0 w-100 h-100 bg-dark opacity-50"></div>

        <!-- Content -->
        <!-- <div class="container position-relative">
        <h1 class="display-3 fw-bold">My Amani</h1>
        <h2 class="h4 mb-3">by TribalSand</h2>

        <p class="fs-5 mb-3">
            <i class="fas fa-map-marker-alt me-2"></i>
            My Amani - Vipingo, Kenya
        </p>

        <div class="d-flex justify-content-center gap-4 fs-5">
            <div>
                <i class="fas fa-users me-1"></i> Sleeps: 10
            </div>
            <div>
                <i class="fas fa-bed me-1"></i> Suites: 5
            </div>
            <div>
                <i class="fas fa-bath me-1"></i> Bathrooms: 5
            </div>
        </div>
    </div> -->



        <div class="row">
            <div class="col-lg-8 mx-auto">

                <p class="text-uppercase small text-info mb-3" style="letter-spacing: 2px;">
                    Where Conservation Meets Sanctuary
                </p>

                <h1 class="display-4 fw-semibold mb-2">
                    The Tribal Sand
                </h1>

                <h2 class="fst-italic fw-light mb-4" style="color:#d6b17c;">
                    Story
                </h2>

                <p class="lead text-light mb-5">
                    A calling written in salt and sand. Where the Kenyan coast meets purpose,
                    and every stay heals both visitor and coastline.
                </p>

                <div class="d-flex justify-content-center gap-3 flex-wrap">
                    <a href="#" class="btn btn-lg px-4 rounded-pill text-dark" style="background-color:#d6b17c;">
                        Discover Our Mission
                    </a>

                    <a href="#" class="btn btn-lg px-4 rounded-pill btn-outline-light">
                        Explore Sanctuaries
                    </a>
                </div>

            </div>
        </div>




    </section>


    <section class="py-5" style="background-color:#F8F6F1">
        <div class="container py-lg-5">

            <!-- Heading -->
            <div class="text-center mb-5">
                <h2 class="fw-semibold mb-1" style="font-size:50px;">
                    A Calling Written in
                </h2>
                <h3 class="fst-italic fw-light text-primary mb-0" style="font-size:50px;">
                    Salt and Sand
                </h3>
            </div>

            <!-- Description -->
            <div class="row">
                <div class="col-lg-8 mx-auto">

                    <p class="fs-5 mb-4">
                        Some people feel the earth’s wounds as their own. For
                        <strong>Dr. Jayen Patel</strong>, it’s not metaphorical—when forests
                        fall and coral bleaches white with death, he carries that grief
                        in his body like a physical ache.
                    </p>

                    <p class="fs-5 mb-4">
                        He became a physician. Built a successful medical practice.
                        But his heart never left the tide pools of his youth, never
                        stopped mourning the ecosystems he’d watched disappear.
                    </p>

                    <p class="fs-5 mb-0">
                        So he made a choice: use the success medicine brought him to
                        return to his first love—the ocean, and the fragile,
                        miraculous life it holds.
                    </p>

                </div>
            </div>

            <!-- Quote Section -->
            <div class="row mt-5">
                <div class="col-lg-8 mx-auto">
                    <blockquote class="blockquote text-center d-flex align-items-center justify-content-center p-4"
                        style="background-color:#F3ECE2; height: 150px;">
                        <p class="mb-0 fst-italic" style="font-size: 1.9rem; color: #2c3e50;">
                            This is how Tribal Sand was born.
                        </p>
                    </blockquote>
                </div>
            </div>

            <!-- New Section: The Coastline That Called Me Home -->
            <div class="row mt-5">
                <div class="col-lg-8 mx-auto">
                    <h3 class="fw-semibold">The Coastline That Called Me Home</h3> </br>
                    <p class="fs-5 mb-4">
                        The Kenyan coast, from Msambweni in the south to beyond Lamu in the north, is where Africa meets
                        the Indian Ocean in a riot of life—mangrove forests breathing with the tides, coral gardens
                        pulsing with color, beaches where ancient turtles have returned for millennia to nest.
                    </p>
                    <p class="fs-5 mb-4">
                        When working with the Kenya Wildlife Service and conservation organizations, what we found broke
                        our hearts. In Kilifi, we saw only dead turtles—victims of boat strikes, drowned in fishing
                        nets. Coral reefs lay bleached and trampled. The beaches stood silent, empty of the new life
                        they once cradled.
                    </p>
                </div>
            </div>

            <!-- New Section: Restoration: A Love Letter in Action -->
            <div class="row mt-5">
                <div class="col-lg-8 mx-auto text-white py-5 px-5 rounded-5" style="background-color:#061F37;">
                    <h3 class="fw-semibold mb-4">Restoration: A Love Letter in Action</h3>
                    <p class="fs-5 mb-4">
                        We started buying land. Not to build, but to save—large swaths where riparian areas could be
                        restored, where sea walls could come down, where the coastline could remember how to breathe.
                    </p>
                    <p class="fs-5 mb-4">
                        For five years, we hand-planted native vegetation along the shore. We worked with the tides,
                        with the seasons, with our hands in the soil. Then a single storm swept through and destroyed it
                        all in two days. We started again.
                    </p>
                </div>
            </div>



        </div>
    </section>

     <section class="  py-5" style="background-color:#F5F0E8">
        <div class="row">
            <div class="col-lg-8 mx-auto text-center">
                <h2 class="fw-semibold" style="font-size:50px;"
                    style="font-family: 'Playfair Display', serif; font-size: 3.5rem; line-height: 1.2;">
                    Four Centuries<br>

                </h2>
                <h2 style="font-family: 'Playfair Display', serif; font-size: 3.5rem; line-height: 1.2;"
                    style="font-size:50px;">

                    One Vision
                </h2>
            </div>
        </div>


        <section class="container py-5">
            <div class="row g-5 align-items-center">

                <!-- Left Image -->
                <div class="col-lg-6">
                    <img src="images\my-amani\My Amani - Outdoor\My Amani Outdoor Day\1MyAmani_Vipingo_TribalSand_Outdoor_1.jpg"
                        class="img-fluid rounded-4" alt="Amani Villa">
                </div>

                <!-- Right Content -->
                <div class="col-lg-6">
                    <small class="text-uppercase text-muted fw-semibold">Vipingo</small>
                    <h2 class="fw-bold mt-2">Amani Villa</h2>
                    <p class="text-muted mt-3" style="font-size:21px;">
                        A meditation sanctuary set on a turtle nesting beach.
                        Amani means “peace” in Swahili — and every detail reflects that promise.
                    </p>

                    <div class="mt-3" style="font-size:21px;">
                        <span class="badge rounded-pill text-bg-light px-3 py-2 me-2 mb-2">
                            Turtle nesting beach
                        </span>
                        <span class="badge rounded-pill text-bg-light px-3 py-2 me-2 mb-2">
                            Meditation gardens
                        </span>
                        <span class="badge rounded-pill text-bg-light px-3 py-2 mb-2">
                            Indigenous reforestation
                        </span>
                    </div>
                </div>

            </div>

            <!-- Second Row -->
            <div class="row g-5 align-items-center mt-5">

                <!-- Left Content -->
                <div class="col-lg-6 order-2 order-lg-1">
                    <small class="text-uppercase text-muted fw-semibold">Kilifi</small>
                    <h2 class="fw-bold mt-2">Maya Kobe</h2>
                    <p class="text-muted mt-3" style="font-size:21px;">
                        12 acres of off-grid paradise powered entirely by solar.
                        A coral farm research station launching 2027 will anchor marine restoration efforts.
                    </p>

                    <div class="mt-3" style="font-size:21px;">
                        <span class="badge rounded-pill text-bg-light px-3 py-2 me-2 mb-2">
                            100% solar powered
                        </span>
                        <span class="badge rounded-pill text-bg-light px-3 py-2 me-2 mb-2">
                            Coral farm 2027
                        </span>
                        <span class="badge rounded-pill text-bg-light px-3 py-2 mb-2">
                            12-acre sanctuary
                        </span>
                    </div>
                </div>

                <!-- Right Image -->
                <div class="col-lg-6 order-1 order-lg-2">
                    <img src="images\maya-kobe\Aerial\mayakobe-2.webp" class="img-fluid rounded-4" alt="Maya Kobe"
                        style="height:400px;">
                </div>

            </div>

            <div class="row g-5 align-items-center" style="padding-top:100px;">

                <!-- Left Image -->
                <div class="col-lg-6">
                    <img src="images\zuri\Garden\zuri.watamu.morning.pool-31.webp" class="img-fluid rounded-4"
                        alt="Amani Villa">
                </div>

                <!-- Right Content -->
                <div class="col-lg-6">
                    <small class="text-uppercase text-muted fw-semibold">Watamu</small>
                    <h2 class="fw-bold mt-2">Zuri</h2>
                    <p class="text-muted mt-3" style="font-size:21px;">
                        Nestled beside the Watamu Marine National Park and the ancient Arabuke Sokoke forest.
                        Dolphins dance offshore while rare birds sing inland.
                    </p>

                    <div class="mt-3" style="font-size:21px;">
                        <span class="badge rounded-pill text-bg-light px-3 py-2 me-2 mb-2">
                            Marine park access
                        </span>
                        <span class="badge rounded-pill text-bg-light px-3 py-2 me-2 mb-2">
                            Arabuke Sokoke forest
                        </span>
                        <span class="badge rounded-pill text-bg-light px-3 py-2 mb-2">
                            Dolphin encounters
                        </span>
                    </div>
                </div>

            </div>

            <div class="row g-5 align-items-center mt-5">

                <!-- Left Content -->
                <div class="col-lg-6 order-2 order-lg-1">
                    <small class="text-uppercase text-muted fw-semibold">Watamu area</small>
                    <h2 class="fw-bold mt-2">Watamu Gateway</h2>
                    <p class="text-muted mt-3" style="font-size:21px;">
                        Your gateway to Tsavo — Kenya's largest national park. Immersive wildlife experiences
                        that connect guests to the raw beauty of East Africa.
                    </p>

                    <div class="mt-3" style="font-size:21px;">
                        <span class="badge rounded-pill text-bg-light px-3 py-2 me-2 mb-2">
                            Gateway to Tsavo
                        </span>
                        <span class="badge rounded-pill text-bg-light px-3 py-2 me-2 mb-2">
                            Wildlife safaris
                        </span>
                        <span class="badge rounded-pill text-bg-light px-3 py-2 mb-2">
                            Coastal wilderness
                        </span>
                    </div>
                </div>

                <!-- Right Image -->
                <div class="col-lg-6 order-1 order-lg-2">
                    <img src="images\blog\IMAGE-1_Top-Luxury-Travel-Trends-in-Kenya-for-2024-2048x1152.webp"
                        class="img-fluid rounded-4" alt="Maya Kobe" style="height:400px;">
                </div>

            </div>
 
        </section>


    </section>




    <!-- <section class="book-now-section" id="book-now-section"> -->
    <!-- <h1 class="page-title">BOOK NOW</h1> -->

    <!-- <div class="booking-container" style="background: transparent; text-align: center; padding-bottom: 0; margin-bottom: 0;"> -->
    <!--<form id="bookingForm">-->
    <!--    <div class="row g-3 mb-3">-->
    <!--        <div class="col-md-4">-->
    <!--            <label class="form-label">Arrival Date</label>-->
    <!--            <div class="date-input-container">-->
    <!--                <input type="date" class="form-control" id="arrivalDate" required>-->
    <!-- <i class="fas fa-calendar calendar-icon"></i> -->
    <!--            </div>-->
    <!--        </div>-->
    <!--        <div class="col-md-3">-->
    <!--            <label class="form-label">Departure Date</label>-->
    <!--            <div class="date-input-container">-->
    <!--                <input type="date" class="form-control" id="departureDate" required>-->
    <!-- <i class="fas fa-calendar calendar-icon"></i> -->
    <!--            </div>-->
    <!--        </div>-->
    <!--        <div class="col-md-5">-->
    <!--            <label class="form-label">Name</label>-->
    <!--            <input type="text" class="form-control" id="name" required>-->
    <!--        </div>-->
    <!--    </div>-->


    <!--    <div class="row g-3 mb-3">-->
    <!--        <div class="col-md-3">-->
    <!--            <label class="form-label">Guests</label>-->
    <!--            <select class="form-select" id="guests" required>-->
    <!--                <option value="" selected disabled>- Select -</option>-->
    <!--                <option value="1">1</option>-->
    <!--                <option value="2">2</option>-->
    <!--                <option value="3">3</option>-->
    <!--                <option value="4">4</option>-->
    <!--                <option value="5">5</option>-->
    <!--                <option value="6">6</option>-->
    <!--                <option value="7">7</option>-->
    <!--                <option value="8">8</option>-->
    <!--                <option value="9">9</option>-->
    <!--                <option value="10">10</option>-->
    <!--            </select>-->
    <!--        </div>-->
    <!--        <div class="col-md-3">-->
    <!--            <label class="form-label">Children</label>-->
    <!--            <select class="form-select" id="children" required>-->
    <!--                <option value="" selected disabled>- Select -</option>-->
    <!--                <option value="0">0</option>-->
    <!--                <option value="1">1</option>-->
    <!--                <option value="2">2</option>-->
    <!--                <option value="3">3</option>-->
    <!--            </select>-->
    <!--        </div>-->
    <!--        <div class="col-md-3">-->
    <!--            <label class="form-label">Phone</label>-->
    <!--            <div class="input-group">-->
    <!--                <div class="phone-input-container">-->
    <!--                    <input type="tel" id="phone" class="form-control">-->
    <!--                    <div class="error-message" id="error-msg"></div>-->
    <!--                    <div class="valid-feedback" id="valid-msg">✓ Valid phone number</div>-->
    <!--                </div>-->
    <!--            </div>-->
    <!--        </div>-->
    <!--        <div class="col-md-3">-->
    <!--            <label class="form-label">Email</label>-->
    <!--            <div class="input-group">-->
    <!--                <input type="email" class="form-control" id="email" required>-->
    <!--                <span class="input-group-text"><i class="fas fa-envelope"></i></span>-->
    <!--            </div>-->
    <!--        </div>-->

    <!--    </div>-->



    <!--    <div class="row g-3 mb-3">-->
    <!--        <div class="col-12">-->
    <!--            <label class="form-label">Comments/Questions</label>-->
    <!--            <textarea class="form-control" id="comments" rows="3"></textarea>-->
    <!--        </div>-->
    <!--    </div>-->

    <!--    <div class="row">-->
    <!--        <div class="col-12">-->
    <!--            <button type="submit" class="send-inquiry-btn">Send Inquiry</button>-->
    <!--        </div>-->
    <!--    </div>-->
    <!--</form>-->
    <!-- OwnerRez Booking/Inquiry widget for My Amani- Full Rental -->
    <!--  <div class="ownerrez-widget" data-widgetId="b550a00e7ad64057a1a5c2727d323d24" data-propertyId="77532faadb614f8da5b076d257ee8d35" data-widget-type="Booking/Inquiry"></div>
            <script type="text/javascript" src="https://app.ownerrez.com/widget.js"></script> -->


    <!-- code for ezee book now button -->

    <!-- <a href="https://book.tribalsand.com/booking/roomwisedata.php?hid=tribalsandlimited&roomtypeunkid=5477100000000000001" class="btn booknow-btn" target="_blank" style="max-width:250px; display: inline-block;">Book Now</a> -->
    <!-- <a href="https://book.tribalsand.com/booking/roomlisting-tribalsand-tribalsandlimited-en" class="btn booknow-btn" target="_blank" style="max-width:250px; display: inline-block;">Book Now</a> -->



    <!-- // code for ezee book now button //  -->
    <!-- <a href="#contatnow" class="btn booknow-btn" style="max-width:250px; display: inline-block;">Book Now</a> -->
    <style>
    /* .booknow-btn{
            background-color: #C5A572;
            color: white;
            padding: 0.8rem 2rem;
            border: none;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            }
            .booknow-btn:hover {
            background-color: #B39362;
            transform: scale(0.9);
            color: white;
            } */
    </style>
    <!-- </div> -->


    <script>
    // document.getElementById('bookingForm').addEventListener('submit', function (e) {
    //     e.preventDefault();

    //     // Gather form data
    //     const formData = {
    //         arrivalDate: document.getElementById('arrivalDate').value,
    //         departureDate: document.getElementById('departureDate').value,
    //         name: document.getElementById('name').value,
    //         guests: document.getElementById('guests').value,
    //         children: document.getElementById('children').value,
    //         email: document.getElementById('email').value,
    //         phone: document.getElementById('countryCode').value + document.getElementById('phone').value,
    //         comments: document.getElementById('comments').value
    //     };

    //     // Here you would typically send the data to your server
    //     // console.log('Form submitted:', formData);
    //     // alert('Booking inquiry sent successfully!');
    //     this.reset();
    // });

    // Format phone number as user types
    // document.getElementById('phone').addEventListener('input', function (e) {
    //     let x = e.target.value.replace(/\D/g, '').match(/(\d{0,3})(\d{0,3})(\d{0,4})/);
    //     e.target.value = !x[2] ? x[1] : '(' + x[1] + ') ' + x[2] + (x[3] ? '-' + x[3] : '');
    // });

    // Set minimum date for arrival and departure
    // const today = new Date().toISOString().split('T')[0];
    // document.getElementById('arrivalDate').min = today;

    // document.getElementById('arrivalDate').addEventListener('change', function () {
    //     document.getElementById('departureDate').min = this.value;
    //     if (document.getElementById('departureDate').value && document.getElementById('departureDate').value < this.value) {
    //         document.getElementById('departureDate').value = this.value;
    //     }
    // });
    </script>

    <!-- </section> -->





    <!--  -->


    <!-- <div class="container py-5"> 
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <button class="nav-link active" data-category="all">All</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-category="aerial">Aerial</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-category="bedrooms">Suites</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-category="entrance">Entrance</button> 
            </li>
            <li class="nav-item">
                <button class="nav-link" data-category="kitchen">Kitchen</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-category="livingRooms">Living Rooms</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-category="outdoor">Outdoor</button>
            </li>
        </ul>
 
        <div class="row g-4" id="galleryGrid">
           
        </div>
        <div class="loader-container" id="loaderContainer">
            <div class="loader"></div>
        </div>
    </div> -->

    <!-- Fullscreen Modal -->
    <div class="modal fade modal-fullscreen" id="imageModal" tabindex="-1" aria-hidden="true" style="z-index: 99999;">
        <button class="modal-close" data-bs-dismiss="modal">
            <i class="fas fa-times"></i>
        </button>
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-body position-relative p-0">
                    <button class="modal-nav-btn modal-prev">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <img src="" class="modal-image w-100" alt="">
                    <button class="modal-nav-btn modal-next">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                    <div class="image-caption"></div>
                </div>
            </div>
        </div>
    </div>





    <!--  -->















    

    

    

      

     

    
    

    

      

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

    <script>
    function scrollReviews(direction) {
        const container = document.getElementById('reviewSlider');
        const cardWidth = 356; // card width + gap
        container.scrollBy({
            left: direction * cardWidth,
            behavior: 'smooth'
        });
    }
    </script>


    <section id="contatnow">
        <?php include 'includes/contact.php'; ?>
    </section>

    <?php include 'includes/footer.php'; ?>



    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>

    <!-- IntlTelInput Script -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/intlTelInput.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/glightbox/3.2.0/js/glightbox.min.js"></script>


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
    document.addEventListener('DOMContentLoaded', () => {
        const slidesData = [{
                image: 'images/Golf.jpg.webp',
                title: 'PGA Baobab Golf Course',
                description: 'Go on a one-of-a-kind golfing experience at Africa’s only 18-Hole, PGA accredited Golf Course.',
                alt: 'Golf'
            },
            {
                image: 'images/Kuruwitu-Conservancy.jpg',
                title: 'Kuruwitu Conservancy',
                description: 'Kayak and snorkel at the Kuruwitu Marine Park, a protected area with over 200 different species of fish.',
                alt: 'Kuruwitu Conservancy'
            },
            {
                image: 'images/Kite-Surfing.jpg.webp',
                title: 'Kitesurfing',
                description: 'Kitesurf with ease along Vipingo’s iconic sandy coastline for a thrilling marine adventure.',
                alt: 'Kitesurfing'
            },
            {
                image: 'images/skydive-tandem-jump-windows-wallpaper-5379579.jpg.webp',
                title: 'Skydiving',
                description: 'Inject some adrenaline into your vacation with a breathtaking bird’s-eye-view of Vipingo Ridge.',
                alt: 'Skydiving'
            },
            {
                image: 'images/sail-7714719_1280.jpg.webp',
                title: 'Traditional Swahili Sunset Dhow Cruise',
                description: 'Let Captain Issa and his crew take you on an unforgettable, sunset trip along the nearby Kilifi Creek.',
                alt: 'Traditional Swahili Sunset Dhow Cruise'
            },
            {
                image: 'images/Arabuko-Sokoke (1).png',
                title: 'Arabuko Sokoke National Reserve',
                description: 'Experience birdwatching like no other with Arabuko Sokoke National Reserve’s unparalleled biodiversity.',
                alt: 'Arabuko Sokoke National Reserve'
            },
            {
                image: 'images/Dolphin-Safari-2.jpg.webp',
                title: 'Watamu-Malindi Beaches',
                description: 'Let loose and relax on the velvety smooth sand at the serene beaches of Watamu and Malindi.',
                alt: 'Watamu-Malindi Beaches'
            },
            {
                image: 'images/Dolphin-sighting.jpg.webp',
                title: 'Dolphin Safari',
                description: 'Say hello to Kenya’s majestic dolphins at Watamu Marine Park’s dolphin haven sanctuaries.',
                alt: 'Dolphin Safari'
            },
            {
                image: 'images/humpback-whale-436120_1280 (1).webp',
                title: 'Seasonal Whale Watching',
                description: 'Take an excursion out on the water for a seasonal whale watching tour at Watamu Marine Park.',
                alt: 'Seasonal Whale Watching'
            },
            {
                image: 'images/Deep-sea-fishing-img-1.jpg.webp',
                title: 'Deep Sea Fishing',
                description: 'Explore a myriad of deep sea fishing options from Vipingo’s local, hands-on fishing tours.',
                alt: 'Deep Sea Fishing'
            },
            {
                image: 'images/MARAFA-HELLS-KITCHEN.jpg.webp',
                title: 'Marafa Hells Kitchen',
                description: 'A picturesque canyon in Malindi. It features jagged sandstone cliffs and formations that rise as high as 30m.',
                alt: 'Marafa Hells Kitchen'
            }
        ];

        class InfiniteCarousel {
            constructor(container, slides) {
                this.container = container;
                this.track = container.querySelector('.activities-track');
                this.slides = slides;
                this.currentIndex = 0;
                this.isTransitioning = false;
                this.autoplayInterval = null;

                // Determine slides per view based on screen size
                this.updateSlidesPerView();
                window.addEventListener('resize', () => this.updateSlidesPerView());

                this.init();
            }

            updateSlidesPerView() {
                if (window.innerWidth <= 768) {
                    this.slidesPerView = 1;
                } else if (window.innerWidth <= 1024) {
                    this.slidesPerView = 2;
                } else {
                    this.slidesPerView = 3;
                }

                // Re-render slides to adapt to new slidesPerView
                if (this.track) {
                    this.renderSlides();
                }
            }

            init() {
                this.renderSlides();
                this.addListeners();
                this.startAutoplay();
            }

            renderSlides() {
                const clonedSlides = [...this.slides];
                const clonedStart = clonedSlides.slice(-this.slidesPerView);
                const clonedEnd = clonedSlides.slice(0, this.slidesPerView);

                // Create expanded slides array for infinite effect
                const expandedSlides = [...clonedStart, ...clonedSlides, ...clonedEnd];

                this.track.innerHTML = expandedSlides
                    .map(slide => `
                    <div class="activity-slide mb-4 mx-2">
                        <div class="activity-card">
                            <img src="${slide.image}" alt="${slide.alt}">
                            <div class="activity-overlay">
                                <h2 class="activity-title">${slide.title}</h2>
                                <p class="activity-description">${slide.description}</p>
                            </div>
                        </div>
                    </div>
                `)
                    .join('');

                this.currentIndex = this.slidesPerView;
                this.updatePosition();
            }

            addListeners() {
                // Navigation buttons
                document.querySelector('.carousel-button-next').addEventListener('click', () => {
                    this.pauseAutoplay();
                    this.moveSlide(1);
                    this.startAutoplay();
                });

                document.querySelector('.carousel-button-prev').addEventListener('click', () => {
                    this.pauseAutoplay();
                    this.moveSlide(-1);
                    this.startAutoplay();
                });

                // Handle mouse interactions
                this.track.addEventListener('mouseenter', () => this.pauseAutoplay());
                this.track.addEventListener('mouseleave', () => this.startAutoplay());

                // Handle transition end
                this.track.addEventListener('transitionend', () => {
                    this.isTransitioning = false;
                    this.handleInfiniteScroll();
                });
            }

            moveSlide(direction) {
                if (this.isTransitioning) return;

                this.isTransitioning = true;
                this.currentIndex += direction;
                this.updatePosition();
            }

            handleInfiniteScroll() {
                const totalSlides = this.slides.length + 2 * this.slidesPerView;

                if (this.currentIndex <= 0) {
                    this.currentIndex = this.slides.length;
                    this.updatePosition(false);
                } else if (this.currentIndex >= totalSlides - this.slidesPerView) {
                    this.currentIndex = this.slidesPerView;
                    this.updatePosition(false);
                }
            }

            updatePosition(animate = true) {
                if (!animate) {
                    this.track.style.transition = 'none';
                } else {
                    this.track.style.transition = 'transform 0.5s ease';
                }

                const offset = -this.currentIndex * (100 / this.slidesPerView);
                this.track.style.transform = `translateX(${offset}%)`;

                if (!animate) {
                    // Force reflow
                    this.track.offsetHeight;
                    this.track.style.transition = 'transform 0.5s ease';
                }
            }

            startAutoplay() {
                if (this.autoplayInterval) return;

                this.autoplayInterval = setInterval(() => {
                    this.moveSlide(1);
                }, 3000); // Change slide every 3 seconds
            }

            pauseAutoplay() {
                if (this.autoplayInterval) {
                    clearInterval(this.autoplayInterval);
                    this.autoplayInterval = null;
                }
            }
        }

        // Initialize the carousel
        new InfiniteCarousel(document.querySelector('.activities-carousel'), slidesData);
    });
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



    <script>
    document.addEventListener('DOMContentLoaded', function() {

        const galleryData = [
            ...[
                'images/my-amani/Aerial/My Amani Best20.jpg',
                'images/my-amani/Aerial/My Amani Best21.jpg',
                'images/my-amani/Aerial/My Amani Best22.jpg',
                'images/my-amani/Aerial/My Amani Best23.jpg',
                'images/my-amani/Aerial/My Amani Best25.jpg',
                'images/my-amani/Aerial/2MyAmani_Vipingo_TribalSand_Aerial8.jpg',
                // 'images/my-amani/Aerial/myamani-10.webp',
                'images/my-amani/Aerial/myamani-11.webp',
                'images/my-amani/Aerial/myamani-12.webp',
                'images/my-amani/Aerial/myamani-2.webp',
                'images/my-amani/Aerial/myamani-3.webp',
                'images/my-amani/Aerial/myamani-4.webp',
                'images/my-amani/Aerial/myamani-5.webp',
                'images/my-amani/Aerial/myamani-6.webp',
                'images/my-amani/Aerial/myamani-7.webp',
                'images/my-amani/Aerial/myamani-8.webp',
                'images/my-amani/Aerial/myamani-9.webp',
                'images/my-amani/Aerial/myamani.webp'
            ].map((src, index) => ({
                id: index + 1,
                src,
                category: 'aerial',
                caption: 'Aerial View'
            })),

            ...[
                'images/my-amani/My Amani - Bedrooms/Bedroom 1/myamani.bedroomlowerright-2.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 1/My Amani Best44.jpg',
                'images/my-amani/My Amani - Bedrooms/Bedroom 1/My Amani Best45.jpg',
                'images/my-amani/My Amani - Bedrooms/Bedroom 1/My Amani Best46.jpg',
                'images/my-amani/My Amani - Bedrooms/Bedroom 1/My Amani Best47.jpg',
                'images/my-amani/My Amani - Bedrooms/Bedroom 1/myamani.bedroom1.bath.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 1/myamani.bedroomlowerright-3.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 1/myamani.gardenbedroombackright-12.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 1/myamani.night.bedroom1-2.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 1/myamani.night.bedroom1.bath-2.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 1/myamani.night.bedroom1.bath.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 1/myamani.night.bedroom1.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 1/myamani.night.bedroomlowerfront-6.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 1/myamani.twinbedroom-6.webp'
            ].map((src, index) => ({
                id: index + 13,
                src,
                category: 'bedrooms',
                type: 'Bedroom 1',
                caption: 'Bedroom 1 View'
            })),

            ...[
                'images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.bedroom1-2.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.bedroom1-3.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.bedroom1-4.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.bedroom1.bath-3.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.bedroom1.bath.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.bedroom1.bathroom-2.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.bedroom1.bathroom-4.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.bedroom1.bathroom-5.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.bedroom1.bathroom.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.bedroom1.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.bedroom1.outdoor-2.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.gardenbedroombackright-10.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.gardenbedroombackright-11.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.gardenbedroombackright-12.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.gardenbedroombackright-13.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.gardenbedroombackright-4.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.gardenbedroombackright-6.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.gardenbedroombackright-8.webp'
            ].map((src, index) => ({
                id: index + 23,
                src,
                category: 'bedrooms',
                type: 'Bedroom 2',
                caption: 'Bedroom 2 View'
            })),

            ...[
                'images/my-amani/My Amani - Bedrooms/Bedroom 3/myamani.bedroomupleftbathroom-2.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 3/myamani.night.twinbed-2.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 3/myamani.night.twinbed.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 3/myamani.night.twinbedbathroom-2.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 3/myamani.night.twinbedbathroom-4.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 3/myamani.night.twinbedbathroom-5.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 3/myamani.night.twinbedbathroom.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 3/myamani.sunset.twinbedroom-2.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 3/myamani.sunset.twinbedroom.webp'
            ].map((src, index) => ({
                id: index + 33,
                src,
                category: 'bedrooms',
                type: 'Bedroom 3',
                caption: 'Bedroom 3 View'
            })),

            ...[
                'images/my-amani/My Amani - Bedrooms/Bedroom 4/myamani.bedroom4.bath-2.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 4/myamani.bedroom4.bath-3.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 4/myamani.bedroom4.bath.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 4/myamani.bedroomupleftbathroom-2.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 4/myamani.twinbedroom-3.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 4/myamani.twinbedroom-4.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 4/myamani.twinbedroom.webp'
            ].map((src, index) => ({
                id: index + 42,
                src,
                category: 'bedrooms',
                type: 'Bedroom 4',
                caption: 'Bedroom 4 View'
            })),

            ...[
                'images/my-amani/My Amani - Bedrooms/Bedroom 5/myamani.bedroom5-2.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 5/myamani.bedroom5-3.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 5/myamani.bedroom5-4.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 5/myamani.bedroom5.bath-2.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 5/myamani.bedroom5.bath-3.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 5/myamani.bedroom5.bath.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 5/myamani.bedroom5.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 5/myamani.night.bedroom5-2.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 5/myamani.night.bedroom5-3.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 5/myamani.night.bedroom5.bath.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 5/myamani.night.bedroom5.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 5/myamani.night.uprightroom-2.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 5/myamani.night.uprightroom-3.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 5/myamani.night.uprightroom-4.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 5/myamani.night.uprightroom-6.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 5/myamani.night.uprightroom-7.webp',
                'images/my-amani/My Amani - Bedrooms/Bedroom 5/myamani.night.uprightroom.webp'
            ].map((src, index) => ({
                id: index + 49,
                src,
                category: 'bedrooms',
                type: 'Bedroom 5',
                caption: 'Bedroom 5 View'
            })),

            ...[
                // 'images/my-amani/My Amani - Entrance/myamani.gardenoutdoor-2.webp',
                // 'images/my-amani/My Amani - Entrance/myamani.gardenoutdoor-3.webp',
                'images/my-amani/My Amani - Entrance/myamani.gardenoutdoor.webp',
                // 'images/my-amani/My Amani - Entrance/myamani.outdoor-45.webp',
                'images/my-amani/My Amani - Entrance/myamani.outdoor-46.webp',
                // 'images/my-amani/My Amani - Entrance/myamani.outdoor-47.webp',
                'images/my-amani/My Amani - Entrance/myamani.outdoor-48.webp',
                // 'images/my-amani/My Amani - Entrance/myamani.outdoor-49.webp',
                'images/my-amani/My Amani - Entrance/myamani.outdoor-50.webp',
                'images/my-amani/My Amani - Entrance/myamani.outdoor-56.webp',
                'images/my-amani/My Amani - Entrance/myamani.outdoor-57.webp'
            ].map((src, index) => ({
                id: index + 66,
                src,
                category: 'entrance',
                caption: 'Entrance View'
            })),

            ...[
                // 'images/my-amani/My Amani - Kitchen/myamani.kitchen-2.webp',
                'images/my-amani/My Amani - Kitchen/myamani.kitchen.webp',
                'images/my-amani/My Amani - Kitchen/myamani.night.kitchen-2.webp',
                // 'images/my-amani/My Amani - Kitchen/myamani.night.kitchen-3.webp',
                // 'images/my-amani/My Amani - Kitchen/myamani.night.kitchen-4.webp',
                'images/my-amani/My Amani - Kitchen/myamani.outdoor-68.webp',
                'images/my-amani/My Amani - Kitchen/myamani.outdoorkitchen-2.webp'
            ].map((src, index) => ({
                id: index + 77,
                src,
                category: 'kitchen',
                caption: 'Kitchen View'
            })),

            ...[
                // 
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.livingarea.webp',
                // 'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.livingroom-10.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.livingroom-11.webp',
                // 'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.livingroom-12.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.livingroom-13.webp',
                // 'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.livingroom-14.webp',
                // 'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.livingroom-15.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/17myamani.livingroom-21.jpg',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.livingroom-16.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.livingroom-17.webp',
                // 'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.livingroom-19.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.livingroom-20.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.livingroom-21.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.livingroom-22.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.livingroom-5.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.livingroom-9.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.sunset.livingroom-2.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.sunset.livingroom-3.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.sunset.livingroom-4.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.sunset.livingroom-7.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Day/myamani.sunset.livingroom.webp'
            ].map((src, index) => ({
                id: index + 84,
                src,
                category: 'livingRooms',
                type: 'Day',
                caption: 'Living Room Day View'
            })),

            ...[
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Night/myamani.night.livingarea-10.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Night/myamani.night.livingarea-15.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Night/myamani.night.livingarea-16.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Night/myamani.night.livingarea-2.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Night/myamani.night.livingarea-3.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Night/myamani.night.livingarea-4.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Night/myamani.night.livingarea-5.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Night/myamani.night.livingarea-6.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Night/myamani.night.livingarea-7.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Night/myamani.night.livingarea-8.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Night/myamani.night.livingarea-9.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Night/myamani.night.livingarea.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Night/myamani.night.livingroom-3.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Night/myamani.night.livingroom-6.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Night/myamani.night.livingroom-7.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Night/myamani.night.livingroom-8.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Night/myamani.night.livingroom-9.webp',
                'images/my-amani/My Amani - LivingRoom/My Amani Livingroom - Night/myamani.night.livingroom.webp'
            ].map((src, index) => ({
                id: index + 104,
                src,
                category: 'livingRooms',
                type: 'Night',
                caption: 'Living Room Night View'
            })),

            ...[
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Night/My Amani Best34.jpg',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Night/My Amani Best35.jpg',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Night/My Amani Best36.jpg',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Night/My Amani Best37.jpg',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/1MyAmani_Vipingo_TribalSand_Outdoor_1.jpg',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/4MyAmani_Vipingo_TribalSand_Outdoor_9.jpg',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/5MyAmani_Vipingo_TribalSand_Outdoor_6.jpg',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/7MyAmani_Vipingo_TribalSand_Outdoor_15.jpg',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/8MyAmani_Vipingo_TribalSand_Outdoor_10.jpg',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/9 MyAmani_Vipingo_TribalSand_Outdoor_12.jpg',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/11MyAmani_Vipingo_TribalSand_Outdoor_2.jpg',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/12 MyAmani_Vipingo_TribalSand_Outdoor_4.jpg',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/14MyAmani_Vipingo_TribalSand_Outdoor_8.jpg',
                // 'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.livingroom-23.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.livingroom-24.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.night.outdoorgarden-15.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-103.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-106.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-108.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-109.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-115.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-116.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-117.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-122.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-123.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-124.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-126.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-128.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-129.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-131.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-132.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-133.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-135.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-136.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-138.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-140.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-141.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-143.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-146.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-147.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-150.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-151.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-152.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-154.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-155.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-156.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-157.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-21.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-22.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-34.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-39.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-43.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-51.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-52.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-53.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-54.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-60.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-62.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-64.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-65.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-66.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-67.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-69.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-70.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-71.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-73.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-74.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-75.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-76.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-77.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-81.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-82.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-83.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-84.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-85.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-88.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-90.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-91.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-93.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-94.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-95.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-96.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-97.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-98.webp',
                'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/myamani.outdoor-99.webp',
            ].map((src, index) => ({
                id: index + 122,
                src,
                category: 'outdoor',
                caption: 'Outdoor View'
            })),
        ];
        const galleryGrid = document.getElementById('galleryGrid');
        const imageModal = new bootstrap.Modal(document.getElementById('imageModal'));
        let currentCategory = 'all';
        let currentImageIndex = 0;

        let imagesPerLoad = 9;
        let currentlyLoadedImages = 0;
        let isLoading = false;

        const loaderContainer = document.getElementById('loaderContainer');

        function attachClickHandlers() {
            document.querySelectorAll('.gallery-item').forEach((item, index) => {
                item.addEventListener('click', () => {
                    currentImageIndex = parseInt(item.dataset.index);
                    showImage(currentImageIndex);
                    imageModal.show();
                });
            });
        }

        function renderGallery(append = false) {
            if (append) {
                loaderContainer.style.display = 'block';
                // Add a small delay to show the loading animation
                setTimeout(() => {
                    renderImages(append);
                    loaderContainer.style.display = 'none';
                }, 800);
            } else {
                renderImages(append);
            }
        }

        // Move your existing render logic to this new function
        function renderImages(append) {
            const filteredImages = galleryData.filter(item => {
                if (currentCategory === 'all') return true;
                return item.category === currentCategory;
            });

            if (!append) {
                galleryGrid.innerHTML = '';
                currentlyLoadedImages = 0;
            }

            const startIndex = currentlyLoadedImages;
            const endIndex = Math.min(startIndex + imagesPerLoad, filteredImages.length);
            const imagesToRender = filteredImages.slice(startIndex, endIndex);

            const newHTML = imagesToRender.map((item, index) => `
                    <div class="col-md-4">
                        <div class="gallery-item fade-in" data-index="${startIndex + index}">
                            <img src="${item.src}" alt="${item.caption}" loading="lazy">
                            <div class="gallery-overlay">
                                <i class="fas fa-expand"></i>
                            </div>
                        </div>
                    </div>
                `).join('');

            galleryGrid.insertAdjacentHTML('beforeend', newHTML);
            currentlyLoadedImages = endIndex;
            isLoading = false;

            // Add click handlers to new gallery items
            attachClickHandlers();
        }


        // Infinite scroll handler
        function handleScroll() {
            if (isLoading) return;

            const scrollPosition = window.innerHeight + window.scrollY;
            const bodyHeight = document.documentElement.scrollHeight;

            const filteredImages = galleryData.filter(item => {
                if (currentCategory === 'all') return true;
                return item.category === currentCategory;
            });

            if (scrollPosition >= bodyHeight - 1000 && currentlyLoadedImages < filteredImages.length) {
                isLoading = true;
                renderGallery(true);
            }
        }

        // Show image in modal
        function showImage(index) {
            const filteredImages = galleryData.filter(item => {
                if (currentCategory === 'all') return true;
                return item.category === currentCategory;
            });

            const image = filteredImages[index];
            const modalImage = document.querySelector('.modal-image');
            const caption = document.querySelector('.image-caption');

            modalImage.src = image.src;
            modalImage.alt = image.caption;
            caption.textContent = image.caption;
        }

        // Navigation event handlers
        document.querySelectorAll('.nav-tabs .nav-link').forEach(link => {
            link.addEventListener('click', function() {
                document.querySelectorAll('.nav-tabs .nav-link').forEach(l => l.classList
                    .remove('active'));
                this.classList.add('active');
                currentCategory = this.dataset.category;
                renderGallery(false); // Reset gallery on category change
            });
        });

        // Modal navigation
        document.querySelector('.modal-prev').addEventListener('click', () => {
            const filteredImages = galleryData.filter(item => {
                if (currentCategory === 'all') return true;
                return item.category === currentCategory;
            });

            currentImageIndex = (currentImageIndex - 1 + filteredImages.length) % filteredImages.length;
            showImage(currentImageIndex);
        });

        document.querySelector('.modal-next').addEventListener('click', () => {
            const filteredImages = galleryData.filter(item => {
                if (currentCategory === 'all') return true;
                return item.category === currentCategory;
            });

            currentImageIndex = (currentImageIndex + 1) % filteredImages.length;
            showImage(currentImageIndex);
        });

        // Add scroll event listener
        window.addEventListener('scroll', handleScroll);

        // Initialize gallery
        renderGallery();
    });
    </script>



</body>

</html>