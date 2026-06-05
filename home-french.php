<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TribalSand</title>
    <link rel="icon" href="images/favicon.png">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.1/font/bootstrap-icons.min.css"
        rel="stylesheet">
    <!-- GLightbox CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/glightbox/3.2.0/css/glightbox.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/main.css">
    <?php include 'includes/g-tag-head.php'; ?>
    <!-- Swiper CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/Swiper/10.2.0/swiper-bundle.min.css" rel="stylesheet">


    <style>
        .hero-section {
            position: relative;
            min-height: 100vh;
            background: linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)),
                url('images/updated-hero-banner_auto_x2.jpg') center/cover no-repeat;
            display: flex;
            align-items: end;
            justify-content: center;
            text-align: center;
            padding: 0 20px;
        }

        .hero-section .hero-title {
            font-family: Marcellus, sans-serif;
            color: white;
            font-size: 56px;
            font-weight: 100;
            letter-spacing: 6px;
            margin-bottom: 2rem;
            line-height: 56px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
        }

        .hero-section .search-container {
            background: #f7f7f3;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 1200px;
            margin-bottom: 15px;
        }

        .hero-section .search-input {
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            padding: 12px;
            width: 100%;
        }

        /* Style for date inputs */
        .hero-section input[type="date"] {
            padding: 12px;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            width: 100%;
            height: auto;
            /* cursor: pointer; */
        }

        .hero-section input[type="date"]::-webkit-calendar-picker-indicator {
            cursor: pointer;
        }

        .hero-section .search-btn {
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            padding: 12px 12px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .hero-section .search-btn:hover {
            background-color: #0056b3;
        }

        @media (max-width: 768px) {
            .hero-section .hero-title {
                font-size: 2rem;
            }

            .hero-section .search-container {
                padding: 15px;
            }

            .hero-section .search-row>div {
                margin-bottom: 15px;
            }
        }
    </style>

    <!-- map section -->
    <style>
        .map-section .map-bg {
            position: relative;
            min-height: 600px;
        }


        .map-section .content-section {
            padding: 4rem 2rem;
        }

        .map-section .main-title {
            font-size: 2.5rem;
            letter-spacing: 2px;
            color: #333;
            margin-bottom: 2rem;
            border-bottom: 2px solid #b8860b;
            padding-bottom: 1.5rem;
            font-weight: 200;
        }

        .map-section .subtitle {
            font-size: 1.1rem;
            color: #666;
            margin-bottom: 2rem;
            line-height: 1.8;
        }

        .map-section .highlight-text {
            color: #b8860b;
            font-weight: 500;
        }

        .map-section .tagline {
            font-size: 1.2rem;
            color: #444;
            margin-top: 2rem;
        }
    </style>

    <!-- property card -->
    <style>
        .property-card {
            margin-bottom: 30px;
            overflow: hidden;
            border-radius: 8px;
            /* box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); */
        }

        .image-container {
            position: relative;
            overflow: hidden;
            height: 300px;
        }

        .image-container img {
            width: 100%;
            height: 100%;
            transition: all 1s ease;
            transform: scale(1.2);
        }

        .image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0);
            transition: all 1s ease;
        }

        .property-logo {
            position: absolute;
            top: 20px;
            left: 20px;
            max-height: 80px;
            z-index: 2;
            width: auto;
            object-fit: contain;
            transform: scale(1);
        }

        .property-card:hover .image-container img {
            transform: scale(1);
        }

        .property-card:hover .image-overlay {
            background: rgba(0, 0, 0, 0.2);
        }

        .property-info {
            padding: 20px;
            background: white;
        }

        .property-title {
            color: #2d2d2d;
            font-size: 24px;
            margin-bottom: 15px;
            font-weight: 300;
        }

        .property-details {
            display: flex;
            gap: 20px;
            color: #000;
            font-family: "Piazzolla", Sans-serif;
            font-weight: 300;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .additional-cottage {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }

        .additional-cottage h6 {
            color: #666;
            margin-bottom: 10px;
        }
    </style>

    <!-- needhelpkenya -->
    <style>
        .needHelpKenya {
            position: relative;
            min-height: 100%;
            background: linear-gradient(rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.7)),
                url('images/43t.jpg') center/cover no-repeat;
        }

        .needHelpKenya::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(128, 128, 128, 0.7);
            /* Gray overlay with 0.7 opacity */
            z-index: 1;
        }

        .needHelpKenya .container {
            position: relative;
            z-index: 2;
            /* Ensure text is above the overlay */
        }

        .needHelpKenya .section-header h2 {
            font-size: 2rem;
            color: #fff;
            margin-bottom: 0.5rem;
            font-weight: 200;
            text-transform: uppercase;
            letter-spacing: 4px;
        }

        .needHelpKenya .section-header p {
            color: #fff;
            font-weight: 300;
            letter-spacing: 1px;
        }

        .needHelpKenya .step-card {
            background: none;
            border: none;
            color: #fff;
            text-align: center;
        }

        .needHelpKenya .step-number {
            font-size: 2rem;
            color: #fff;
            margin-bottom: 0.5rem;
            font-weight: bold;
        }

        .needHelpKenya .step-card h4 {
            color: #fff;
            margin-bottom: 0.5rem;
            font-weight: bold;
        }

        .needHelpKenya .contact-info {
            color: #fff;
            padding-top: 2rem;
            text-align: center;
            border-top: 2px solid;
        }

        .needHelpKenya .contact-info a {
            color: #fff;
            text-decoration: none;
        }
    </style>

    <!-- luxuriousbeach -->
    <style>
        .LuxuriousBeach .row {
            height: 100%;
        }

        .LuxuriousBeach .hero-section2 {
            background-color: #fcf1d9;
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
            color: #2d2d2d;
            margin-bottom: 0.5rem;
            font-weight: 200;
            text-transform: uppercase;
            letter-spacing: 4px;
        }

        .LuxuriousBeach .main-title::after {
            content: "";
            display: block;
            width: 25%;
            margin-top: 1.5rem;
            border-bottom: 2px solid #c9a96a;
        }

        .LuxuriousBeach .description {
            color: #000;
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

    <!-- amenities section -->
    <style>
        .amenities-section {
            background: linear-gradient(#081323DE, #081323DE),
                url('images/tribal-2.jpg');
            background-size: cover;
            background-position: center;
            padding: 40px 0;
            color: white;
        }

        .amenities-section .section-title {
            margin-bottom: 40px;
            position: relative;
            font-weight: 200;
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

    <!-- testominial section -->
    <style>
        .testimonial-section {
            background: url('images/Image1.jpg');
            background-size: cover;
            background-position: center;
        }

        .main-heading {
            text-align: left;
            color: #fff;
            margin-bottom: 3rem;
            font-weight: 300;
            letter-spacing: 4px;
        }

        .testimonial-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin: 1rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            position: relative;
        }

        .testimonial-text {
            font-size: 1.1rem;
            line-height: 1.6;
            color: #2c3f58;
            margin-bottom: 1.5rem;
            font-weight: 300;
        }

        .testimonial-author {
            font-weight: 600;
            color: #2c3f58;
            margin-bottom: 0.2rem;
            font-family: "Marcellus SC", Sans-serif;
            font-size: 24px;
            font-weight: 400;
        }

        .testimonial-location {
            color: #2c3f58;
            font-size: 0.9rem;
            font-family: "Roboto Slab", Sans-serif;
            font-weight: 400;
        }

        .verified-badge {
            display: inline-block;
            background: #28a745;
            color: white;
            font-size: 0.8rem;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            margin-left: 0.5rem;
        }

        /* Swiper customization */
        .swiper {
            padding: 2rem 1rem;
        }

        .swiper-button-next,
        .swiper-button-prev {
            color: #fff;
            background-color: #a6a6a6;
            border-radius: 50%;
            padding: 18px;
        }

        .swiper-button-next:after,
        .swiper-button-prev:after {
            font-size: 28px;
        }

        .swiper-pagination-bullet-active {
            background: #fff;
        }

        /* Quote decoration */
        .testimonial-text::before {
            content: '"';
            font-size: 4rem;
            color: #e9ecef;
            position: absolute;
            top: 1rem;
            left: 1rem;
            font-family: Georgia, serif;
            opacity: 0.3;
        }

        @media (max-width: 768px) {
            .testimonial-section {
                padding: 2rem 0;
            }

            .testimonial-card {
                margin: 0.5rem;
            }
        }
    </style>

    <!-- video display CSS -->
    <style>
        .kenya-section {
            background-color: #fcf1d9;
            padding: 4rem 0;
        }

        .kenya-section .section-titlee {
            font-size: 36px;
            font-weight: 200;
            letter-spacing: 4px;
            line-height: 36px;
            text-decoration: none solid rgb(45, 45, 45);
            color: #2d2d2d;
        }

        .kenya-section .divider {
            width: 50px;
            height: 2px;
            background-color: #d4b88c;
            margin: 1.5rem 0;
        }

        .kenya-section .section-text {
            /* font-family: montserrat; */
            font-size: 16px;
            font-weight: 300;
            letter-spacing: 2px;
            line-height: 24px;
            text-decoration: none solid rgb(45, 45, 45);
            text-align: left;
        }

        .kenya-section .video-container {
            position: relative;
            padding-bottom: 56.25%;
            height: 0;
            overflow: hidden;
        }

        .kenya-section .video-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .kenya-section .play-button {
            font-size: 7rem;
            color: white;
            opacity: 0.9;
            transition: all 0.3s ease;
        }

        .kenya-section .video-overlay:hover .play-button {
            transform: scale(1.1);
            opacity: 1;
        }
    </style>

    <!-- Slider CSS -->
    <style>
        .tourist-activities-section {
            /* max-width: 1400px; */
            margin: 40px auto;
            padding: 20px 20px;
            background-color: #fff;
            border-radius: 12px;
        }

        .section-heading {
            font-family: Marcellus SC, sans-serif;
            font-size: 25px;
            font-weight: 300;
            line-height: 25px;
            text-decoration: none solid rgb(0, 0, 0);
            text-align: center;
            margin-bottom: 40px;
            color: #1a1a1a;
        }

        .activities-carousel {
            position: relative;
            overflow: hidden;
            padding: 0 40px;
        }

        .activities-track {
            display: flex;
            transition: transform 0.5s ease;
            gap: 5px;
        }

        .activity-slide {
            flex: 0 0 calc(33.333% - 14px);
            min-width: calc(33.333% - 14px);
        }

        .activity-card {
            position: relative;
            height: 650px;
            border-radius: 5px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            background-color: #f8f5f2;
        }

        .activity-card img {
            width: 100%;
            height: 75%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .activity-card:hover img {
            transform: scale(1.05);
        }

        .card-content {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 25%;
            padding: 20px;
            background-color: #e6dac2;
            color: #333;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .activity-title {
            margin-bottom: 8px;
            text-align: center;
            font-family: Manrope, sans-serif;
            font-size: 24px;
            font-weight: 300;
            line-height: 28.8px;
            text-decoration: none solid rgb(45, 45, 45);
        }

        .activity-description {
            color: #666;
            text-align: center;
            max-width: 90%;
            margin: 0 auto;
            font-family: Manrope, sans-serif;
            font-size: 15px;
            font-weight: 300;
            line-height: 22.5px;
            text-decoration: none solid rgb(45, 45, 45);
        }

        .carousel-button {
            position: absolute;
            top: 37.5%;
            transform: translateY(-50%);
            width: 50px;
            height: 50px;
            background-color: rgba(255, 255, 255, 0.95);
            border: none;
            border-radius: 50%;
            cursor: pointer;
            z-index: 10;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #333;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
        }

        .carousel-button:hover {
            background-color: white;
            transform: translateY(-50%) scale(1.1);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .carousel-button-prev {
            left: 2px;
        }

        .carousel-button-next {
            right: 2px;
        }

        @media (max-width: 1024px) {
            .activity-slide {
                flex: 0 0 calc(50% - 10px);
                min-width: calc(50% - 10px);
            }

            .activity-card {
                height: 450px;
            }

            .section-heading {
                font-size: 32px;
            }
        }

        @media (max-width: 768px) {
            .activity-slide {
                flex: 0 0 100%;
                min-width: 100%;
            }

            .activity-card {
                height: 400px;
            }

            .activity-title {
                font-size: 20px;
            }

            .section-heading {
                font-size: 28px;
                margin-bottom: 30px;
            }

            .tourist-activities-section {
                margin: 20px;
                padding: 20px;
            }
        }
    </style>

    <!-- adventure section and footer css -->
    <style>
        .adventure-section {
            background-image: url('images/a-g-5.jpg');
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
            font-family: montserrat, sans-serif;
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
            padding: 16px 48px;
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
            text-align: center;
            word-spacing: 0;
        }

        .adventure-section .btn-reservation:hover {
            transform: scale(0.95);
            background-color: #45a049;
            color: white;
        }

        .adventure-section .btn-reservation i {
            transition: transform 0.3s ease;
        }

        .adventure-section .btn-reservation:hover i {
            transform: translateX(5px);
        }

        /* Media Queries for all devices */
        @media (max-width: 1200px) {
            .adventure-section .adventure-title {
                font-size: 32px;
                line-height: 42px;
            }

            .adventure-section .adventure-subtitle {
                font-size: 14px;
                line-height: 20px;
            }

            .adventure-section .btn-reservation {
                font-size: 18px;
                padding: 14px 40px;
                width: 100%;
            }
        }

        @media (max-width: 992px) {
            .adventure-section {
                padding: 100px 0;
            }

            .adventure-section .adventure-title {
                font-size: 28px;
                line-height: 38px;
            }

            .adventure-section .adventure-subtitle {
                font-size: 14px;
            }

            .adventure-section .btn-reservation {
                font-size: 16px;
                padding: 12px 30px;
            }
        }

        @media (max-width: 768px) {
            .adventure-section .adventure-title {
                font-size: 24px;
                line-height: 34px;
            }

            .adventure-section .btn-reservation {
                font-size: 16px;
                padding: 10px 25px;
            }
        }

        @media (max-width: 576px) {
            .adventure-section {
                padding: 70px 0;
            }

            .adventure-section .adventure-title {
                /* font-size: 20px; */
                line-height: 30px;
            }

            .adventure-section .adventure-subtitle {
                font-size: 12px;
            }

            .adventure-section .btn-reservation {
                font-size: 14px;
                padding: 8px 20px;
            }
        }
    </style>

</head>

<body>
    <?php include 'includes/g-tag-body.php'; ?>

    <?php include 'includes/header.php'; ?>


    <section class="hero-section">
        <div class="container">
            <h1 class="hero-title">PLANIFIEZ VOTRE EXPÉRIENCE DE VACANCES KÉNYANE ULTIME</h1>
        </div>

        <script>
            document.addEventListener("DOMContentLoaded", function () {
                const today = new Date().toISOString().split('T')[0];

                // Initialize Flatpickr on arrival and departure date inputs
                flatpickr("#arrival-date", {
                    minDate: today,
                    dateFormat: "Y-m-d",
                    onChange: function (selectedDates, dateStr) {
                        // Update the minDate of departure based on selected arrival date
                        document.getElementById("departure-date")._flatpickr.set("minDate", dateStr);
                    }
                });

                flatpickr("#departure-date", {
                    minDate: today,
                    dateFormat: "Y-m-d"
                });
            });
        </script>

    </section>

    <section class="container p-0 map-section">
        <div class="row g-0">
            <!-- Map Section -->
            <div class="col-md-5 map-bg d-flex justify-content-center align-items-center">
                <img src="images/Map-Graphic.png.webp" alt="map" class="img-fluid">
            </div>

            <!-- Content Section -->
            <div class="col-md-7">
                <div class="content-section">
                    <p class="text-muted mb-4 text-center" style="font-size: 25px;">Bienvenue chez Tribal Sand</p>

                    <h1 class="main-title text-center">Découvrez la beauté du Kenya</h1>

                    <p class="subtitle text-center">
                    Chacune de nos propriétés est conçue de manière unique pour offrir la combinaison idéale de confort et d’aventure pour votre prochaine escapade de luxe.
                    </p>

                    
                </div>
            </div>
        </div>
    </section>

    <section>
        <div class="container py-5">
            <div class="row">
                <!-- First Property Card -->
                <div class="col-lg-6">
                    <div class="property-card">
                        <div class="image-container">
                            <img src="images/My-Amani-8.jpg.webp" alt="My Amani">
                            <div class="image-overlay"></div>
                            <div class="property-logo">
                                <img src="images/My-amanI-WHITE-1.png" alt="My Amani Logo" class="img-fluid">
                            </div>

                        </div>
                        <div class="property-info">
                            <h3 class="property-title">My Amani - Vipingo, Kenya</h3>
                            <div class="property-details">
                                <div class="detail-item">
                                    <i class="fas fa-users"></i>
                                    <span>Sleeps: 10</span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-bed"></i>
                                    <span>Bedrooms: 5</span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-bath"></i>
                                    <span>Bathrooms: 5</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Second Property Card -->
                <div class="col-lg-6">
                    <div class="property-card">
                        <div class="image-container">
                            <img src="images/Copy-of-169543_278172925622515_47485583_o-min.jpg.webp" alt="Maya Kobe">
                            <div class="image-overlay"></div>
                            <div class="property-logo">
                                <img src="images/Maya-Kobe-WHITE-1.png" alt="Maya Kobe Logo" class="img-fluid">
                            </div>
                        </div>
                        <div class="property-info">
                            <h3 class="property-title">Maya Kobe - Bofa Road, Kilifi, Kenya</h3>
                            <div class="property-details">
                                <div class="detail-item">
                                    <i class="fas fa-users"></i>
                                    <span>Sleeps: 8</span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-bed"></i>
                                    <span>Bedrooms: 4</span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-bath"></i>
                                    <span>Bathrooms: 4</span>
                                </div>
                            </div>
                            <div class="additional-cottage">
                                <h6>Additional Cottage:</h6>
                                <div class="property-details">
                                    <div class="detail-item">
                                        <i class="fas fa-users"></i>
                                        <span>Sleeps: 4</span>
                                    </div>
                                    <div class="detail-item">
                                        <i class="fas fa-bed"></i>
                                        <span>Bedrooms: 2</span>
                                    </div>
                                    <div class="detail-item">
                                        <i class="fas fa-bath"></i>
                                        <span>Bathrooms: 2</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <section class="py-5 needHelpKenya">
        <div class="container">
            <div class="section-header text-center mb-5">
                <h2>Besoin d'aide pour voyager au Kenya ?</h2>
                <p>Laissez-nous vous guider à chaque étape</p>
                <p>Découvrir la côte extraordinaire du Kenya est une expérience à ne pas manquer. Cependant, la gestion de toutes les logistiques et des prix pour que cela se réalise peut sembler une tâche intimidante. Laissez-nous vous aider tout au long du processus afin que nous puissions concrétiser votre expérience.</p>
            </div>
            <div class="row text-center">
                <div class="col-md-4 mb-4">
                    <div class="step-card">
                        <div class="step-number"><span
                                style="background: white; padding: 10px 25px; border-radius: 50%; color: #2C3F58;font-family: 'Marcellus SC', Sans-serif;">1</span>
                        </div>
                        <h4>Découvrez avec nos spécialistes de Tribal Sand</h4>
                        <p>Notre équipe vous aidera à choisir la maison qui correspond à la vision de votre groupe.</p>
                    </div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="step-card">
                        <div class="step-number"><span
                                style="background: white; padding: 10px 25px; border-radius: 50%; color: #2C3F58;font-family: 'Marcellus SC', Sans-serif;">2</span>
                        </div>
                        <h4>Planifiez à l'avance avec notre équipe de curateurs</h4>
                        <p>Personnalisez vos vacances avec des experts de la côte kényane et planifiez tout, des courses aux safaris.</p>
                    </div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="step-card">
                        <div class="step-number"><span
                                style="background: white; padding: 10px 25px; border-radius: 50%; color: #2C3F58;font-family: 'Marcellus SC', Sans-serif;">3</span>
                        </div>
                        <h4>Notre équipe sur place vous accueille</h4>
                        <p>Dès le moment où vous franchissez les portes de nos villas, votre hôte est là pour vous assister et s’assurer que votre voyage soit tout ce que vous désirez.</p>
                    </div>
                </div>
            </div>
            <div class="contact-info">
                <p>+254115115247 - WhatsApp</p>
                <p><a href="mailto:info@tribalsand.com">info@tribalsand.com</a></p>
            </div>
        </div>
    </section>

    <section class="LuxuriousBeach py-4">
        <div class="container">
            <div class="row g-0">
                <div class="col-12 col-lg-6">
                    <div class="hero-section2">
                        <div class="content-wrapper">
                            <h1 class="main-title">LUXUEUSES LOCATIONS DE VILLAS EN BORD DE MER</h1>
                            <div class="description">
                                <p>Découvrez le Kenya tel qu’il était destiné à être vécu. Faites votre choix entre deux destinations kényanes exquises : le style balinais de Maya Kobe à Kilifi et le secret le mieux gardé de Vipingo, My Amani.</p>
                                <p>Contemplez l’océan Indien dans toute sa splendeur depuis ces locations spectaculaires et explorez les environs pour découvrir des aventures en abondance, de quoi remplir toute une vie.</p>
                            </div>
                            <div class="d-flex">
                                <a href="#" class="btn book-btn">Réservez une demande de renseignements →</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-6" style="min-height: 35vh">
                    <div class="slider-container">
                        <img src="images/a4-1.jpg" alt="Luxury Villa 1" class="slider-image active">
                        <img src="images/D8.jpg" alt="Luxury Villa 2" class="slider-image">
                        <img src="images/D9-1.jpg" alt="Luxury Villa 3" class="slider-image">
                    </div>
                </div>
            </div>
        </div>

        <script>
            function initializeSlider() {
                const images = document.querySelectorAll('.LuxuriousBeach .slider-image');
                let currentIndex = 0;

                function showNextImage() {
                    images[currentIndex].classList.remove('active');
                    currentIndex = (currentIndex + 1) % images.length;
                    images[currentIndex].classList.add('active');
                }

                setInterval(showNextImage, 3000);
            }

            document.addEventListener('DOMContentLoaded', initializeSlider);
        </script>
    </section>

    <section class="amenities-section">
        <div class="container">
            <h2 class="section-title">ÉQUIPEMENTS PREMIUM PROPOSÉS DANS NOTRE PARADIS CACHÉ</h2>

            <p class="text-left intro-text">
            Lors de votre séjour chez Tribal Sand, vous profiterez de ce que les vacances de luxe ont de mieux à offrir dans nos 2 villas:
            </p>

            <div class="row">
                <div class="col-lg-6">
                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-umbrella-beach"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>Accès direct à une plage exclusive et préservée</h5>
                        </div>
                    </div>

                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-leaf"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>Côte bien préservée qui permet à la faune locale et à vous-même de prospérer</h5>
                        </div>
                    </div>

                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>Domaine privé avec sécurité 24 heures sur 24</h5>
                        </div>
                    </div>

                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-tree"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>Jardin verdoyant rempli d'arbres et de plantes indigènes uniques</h5>
                        </div>
                    </div>

                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-plane"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>
                            Transferts depuis l'aéroport disponibles sur demande</h5>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-compass"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>Accès à proximité à une aventure safari et aux villes locales le long de la côte</h5>
                        </div>
                    </div>

                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-home"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>Architecture majestueuse et ambiance chaleureuse</h5>
                        </div>
                    </div>

                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-utensils"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>Un chef privé est disponible sur demande</h5>
                        </div>
                    </div>

                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-coffee"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>Cuisine autonome</h5>
                        </div>
                    </div>

                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-wifi"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>Wi-Fi gratuit</h5>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-left">
                <button class="book-btn">Réservez dès aujourd'hui votre réservation →</button>
            </div>
        </div>
    </section>

    <section class="testimonial-section py-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-6">
                    <h2 class="main-heading">EXPÉRIMENTEZ UN PARADIS COMME NUL AUTRE</h2>
                    <div class="swiper testimonial-swiper">
                        <div class="swiper-wrapper">
                            <!-- Testimonial 1 -->
                            <div class="swiper-slide">
                                <div class="testimonial-card">
                                    <div class="testimonial-text">
                                        "Stunning place, great staff - friendly and attentive. They have made our visit
                                        perfect. Fabulous location, we will be coming back again and again. Thank you so
                                        much!"
                                    </div>
                                    <div class="testimonial-author">
                                        Mr & Mrs B Vyas
                                        <span class="verified-badge">Verified Review</span>
                                    </div>
                                    <div class="testimonial-location">UK - 2 Aug 2023</div>
                                </div>
                            </div>

                            <!-- Testimonial 2 -->
                            <div class="swiper-slide">
                                <div class="testimonial-card">
                                    <div class="testimonial-text">
                                        "I wanted to take moment to send you a massive asante for enabling me to curate
                                        an incredible birthday celebration. It was definitely one for the books. Your
                                        Home is absolutely spectacular. As an art, architecture and interiors fanatic it
                                        was right up my visual alley. Such a relaxing zen atmosphere. And the staff…
                                        like no other. The care for detail was absolutely on point. They all felt like
                                        family to me and took such great care of me and my friends. They made my stay
                                        extra special."
                                    </div>
                                    <div class="testimonial-author">
                                        Erin
                                        <span class="verified-badge">Verified Review</span>
                                    </div>
                                    <div class="testimonial-location">United States</div>
                                </div>
                            </div>

                            <!-- Testimonial 3 -->
                            <div class="swiper-slide">
                                <div class="testimonial-card">
                                    <div class="testimonial-text">
                                        "This was the perfect place for us to relax and unwind. We love the house,
                                        modern layout, pool, outdoor shower, beach… everything!"
                                    </div>
                                    <div class="testimonial-author">
                                        Viv & Peter Thairu
                                        <span class="verified-badge">Verified Review</span>
                                    </div>
                                    <div class="testimonial-location">United States</div>
                                </div>
                            </div>

                            <!-- Testimonial 4 -->
                            <div class="swiper-slide">
                                <div class="testimonial-card">
                                    <div class="testimonial-text">
                                        "We will always remember this stunning place–its vibrance, its calm aura, its
                                        unbelievably kind staff. Our five days were filled with astonishment. Thank you
                                        with all our hearts!"
                                    </div>
                                    <div class="testimonial-author">
                                        Sonal Patel
                                        <span class="verified-badge">Verified Review</span>
                                    </div>
                                    <div class="testimonial-location">United States</div>
                                </div>
                            </div>

                            <!-- Testimonial 5 -->
                            <div class="swiper-slide">
                                <div class="testimonial-card">
                                    <div class="testimonial-text">
                                        "Beautiful home and incredible people. Loved our time here and can't wait to
                                        come back!"
                                    </div>
                                    <div class="testimonial-author">
                                        Dev and Angela
                                        <span class="verified-badge">Verified Review</span>
                                    </div>
                                    <div class="testimonial-location">United States</div>
                                </div>
                            </div>
                        </div>

                        <!-- Navigation -->
                        <div class="swiper-button-next"></div>
                        <div class="swiper-button-prev"></div>
                        <div class="swiper-pagination"></div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/Swiper/10.2.0/swiper-bundle.min.js"></script>

    <script>
        const swiper = new Swiper('.testimonial-swiper', {
            // Optional parameters
            loop: true,
            spaceBetween: 30,
            centeredSlides: true,

            // Responsive breakpoints
            breakpoints: {
                // when window width is >= 320px
                320: {
                    slidesPerView: 1,
                    spaceBetween: 20
                },
                // when window width is >= 768px
                768: {
                    slidesPerView: 1,
                    spaceBetween: 30
                },
                // when window width is >= 1024px
                1024: {
                    slidesPerView: 1,
                    spaceBetween: 30
                }
            },

            // Navigation arrows
            navigation: {
                nextEl: '.swiper-button-next',
                prevEl: '.swiper-button-prev',
            },

            // Pagination
            pagination: {
                el: '.swiper-pagination',
                clickable: true,
            },

            // Auto play
            autoplay: {
                delay: 5000,
                disableOnInteraction: false,
            },
        });
    </script>

    <section class="kenya-section">
        <div class="container">
            <div class="row align-items-center">
                <!-- Left Column -->
                <div class="col-lg-4 mb-4 mb-lg-0">
                    <h3 class="section-titlee">KENYA AT ITS VERY BEST</h3>
                    <div class="divider"></div>
                    <p class="section-text">
                        Explore the malaria-free Borana conservancy with a memorable African safari, or experience
                        beachside bliss in the coastal town of Kilifi.
                    </p>
                </div>

                <!-- Right Column -->
                <div class="col-lg-8">
                    <div class="video-container">
                        <div class="video-overlay" style="background-image: url('images/Kenya-coast.jpg')"
                            onclick="playVideo(this)">
                            <i class="far fa-play-circle play-button"></i>
                        </div>
                        <div id="video-frame"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="tourist-activities-section">
        <h2 class="section-heading">ADVENTURE AND ACTIVITES</h2>
        <div class="activities-carousel">
            <div class="activities-track">
                <!-- Slides will be added dynamically -->
            </div>
            <button class="carousel-button carousel-button-prev">←</button>
            <button class="carousel-button carousel-button-next">→</button>
        </div>
    </div>

    <section class="adventure-section">
        <div class="container">
            <div class="row">
                <div class="col-md-8 offset-md-2">
                    <div class="adventure-content">
                        <h2 class="adventure-title">Make Room<br> for Adventure</h2>
                        <h4 class="adventure-subtitle">Book your room right now and start your luxurious stay at Maya Kobe.</h4>
                        <a href="#" class="btn-reservation">
                            <span>Book a Reservation Today</span>
                            <i class="fas fa-long-arrow-alt-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include 'includes/footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/glightbox/3.2.0/js/glightbox.min.js"></script>


    <!-- Slider script -->
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const slidesData = [{
                    image: "images/Kite-Surfing.jpg",
                    title: "Dhow Cruise",
                    description: "Magical sunset along Kilifi Creek",
                    alt: "Dhow",
                },
                {
                    image: "images/Kuruwitu-Conservancy.jpg",
                    title: "Beach Fun",
                    description: "Water sports on pristine beaches",
                    alt: "Beach",
                },
                {
                    image: "images/My-Amani-8.jpg",
                    title: "Snorkeling",
                    description: "Explore vibrant marine life",
                    alt: "Snorkeling",
                },
                {
                    image: "images/Dolphin-sighting.jpg",
                    title: "Safari",
                    description: "Wildlife in natural habitat",
                    alt: "Safari",
                },
            ];

            class Carousel {
                constructor(container, slides) {
                    this.container = container;
                    this.track = container.querySelector(".activities-track");
                    this.slides = slides;
                    this.currentIndex = 0;

                    this.updateSlidesPerView();
                    window.addEventListener("resize", () => this.updateSlidesPerView());

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

                    this.renderSlides();
                }

                init() {
                    this.renderSlides();
                    this.addListeners();
                }

                renderSlides() {
                    const clonedSlides = [...this.slides];
                    const clonedStart = clonedSlides.slice(0, this.slidesPerView);
                    const clonedEnd = clonedSlides.slice(-this.slidesPerView);

                    this.track.innerHTML = clonedEnd
                        .concat(clonedSlides, clonedStart)
                        .map(
                            (slide) => `
                            <div class="activity-slide">
                                <div class="activity-card">
                                    <img src="${slide.image}" alt="${slide.alt}">
                                    <div class="card-content">
                                        <h3 class="activity-title">${slide.title}</h3>
                                        <p class="activity-description">${slide.description}</p>
                                    </div>
                                </div>
                            </div>
                        `
                        )
                        .join("");

                    this.currentIndex = this.slidesPerView;
                    this.updatePosition();
                }

                addListeners() {
                    document
                        .querySelector(".carousel-button-next")
                        .addEventListener("click", () => {
                            this.moveSlide(1);
                        });

                    document
                        .querySelector(".carousel-button-prev")
                        .addEventListener("click", () => {
                            this.moveSlide(-1);
                        });
                }

                moveSlide(direction) {
                    this.currentIndex += direction;
                    this.updatePosition();

                    const totalSlides = this.slides.length + 2 * this.slidesPerView;

                    setTimeout(() => {
                        if (this.currentIndex === 0) {
                            this.currentIndex = this.slides.length;
                            this.updatePosition(false);
                        } else if (
                            this.currentIndex ===
                            totalSlides - this.slidesPerView
                        ) {
                            this.currentIndex = this.slidesPerView;
                            this.updatePosition(false);
                        }
                    }, 500);
                }

                updatePosition(animate = true) {
                    if (!animate) this.track.style.transition = "none";
                    const offset = -this.currentIndex * (100 / this.slidesPerView);
                    this.track.style.transform = `translateX(${offset}%)`;
                    if (!animate)
                        setTimeout(
                            () => (this.track.style.transition = "transform 0.5s ease"),
                            0
                        );
                }
            }

            new Carousel(
                document.querySelector(".activities-carousel"),
                slidesData
            );
        });
    </script>
    <!-- Slider script -->

    <script>
        $(document).ready(function() {
            $('.dropdown-toggle').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var $dropdownMenu = $(this).next('.dropdown-menu');
                var isSubmenu = $dropdownMenu.hasClass('dropdown-submenu');

                if (!isSubmenu) {
                    $('.dropdown-menu').not($dropdownMenu).removeClass('show');
                    $('.dropdown-toggle').not($(this)).attr('aria-expanded', 'false');
                } else {
                    var $siblingSubmenus = $(this).closest('.dropdown-menu')
                        .find('.dropdown-submenu')
                        .not($dropdownMenu);

                    $siblingSubmenus.removeClass('show');
                    $siblingSubmenus.prev('.dropdown-toggle').attr('aria-expanded', 'false');
                }

                $dropdownMenu.toggleClass('show');

                $(this).attr('aria-expanded', function(_, attr) {
                    return attr === 'true' ? 'false' : 'true';
                });
            });

            $(document).on('click', function(e) {
                if (!$(e.target).closest('.dropdown').length) {
                    $('.dropdown-menu').removeClass('show');
                    $('.dropdown-toggle').attr('aria-expanded', 'false');
                }
            });

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