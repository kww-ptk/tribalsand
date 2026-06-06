<?php
$booking_slug = 'maya-kobe-cottages';
?>
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
            position: relative;
            min-height: 100vh;
            background: linear-gradient(rgba(0, 0, 0, 0.0), rgba(0, 0, 0, 0.0)),
                url('images/1t.jpg') center/cover no-repeat;
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
            padding: 2rem 3rem;
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

        .LuxuriousBeach .main-title1 {
            font-size: 1.7rem;
            letter-spacing: 2px;
            color: #FFFFFF;
            margin-bottom: 0.5rem;
            font-weight: 200;
            text-transform: uppercase;
            letter-spacing: 4px;
            max-width: 700px;
            margin: auto;
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
            height: 60%;
            position: relative;
            overflow: hidden;
            left: -40px;
            margin-top: 25%;
            margin-bottom: 20%;
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

        @media (max-width: 992px) {
            .LuxuriousBeach .slider-container {
                height: 100%;
                left: 0px;
                margin-top: 0;
                margin-bottom: 0;
            }

            .LuxuriousBeach .content-wrapper {
                padding: 2rem;
            }
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
                url('images/210506_278171415622666_2017261843_o.jpg');
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
            background-image: url('images/399074_278178245621983_624089690_n.jpg');
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
    <link rel="stylesheet" href="css/booking.css">
    <script src="js/booking-widget.js" defer></script>

</head>

<body>
    <?php include 'includes/g-tag-body.php'; ?>

    <?php include 'includes/header.php'; ?>

    <section class="hero-section">
        <div class="container">
            <h2 class="hero-subtitle">LUXURIATE IN THE SANDS</h2>
            <h1 class="hero-title">OF Kilifi, KENYA</h1>
        </div>
    </section>

    <section class="book-now-section">
        <h1 class="page-title">BOOK NOW</h1>

        <div class="booking-container">
            <form id="bookingForm">
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Arrival Date</label>
                        <div class="date-input-container">
                            <input type="date" class="form-control" id="arrivalDate" required>
                            <!-- <i class="fas fa-calendar calendar-icon"></i> -->
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Departure Date</label>
                        <div class="date-input-container">
                            <input type="date" class="form-control" id="departureDate" required>
                            <!-- <i class="fas fa-calendar calendar-icon"></i> -->
                        </div>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control" id="name" required>
                    </div>
                </div>


                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Guests</label>
                        <select class="form-select" id="guests" required>
                            <option value="" selected disabled>- Select -</option>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>

                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Children</label>
                        <select class="form-select" id="children" required>
                            <option value="" selected disabled>- Select -</option>
                            <option value="0">0</option>
                            <option value="1">1</option>
                            <option value="2">2</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Phone</label>
                        <div class="input-group">
                            <div class="phone-input-container">
                                <input type="tel" id="phone" class="form-control">
                                <div class="error-message" id="error-msg"></div>
                                <div class="valid-feedback" id="valid-msg">✓ Valid phone number</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Email</label>
                        <div class="input-group">
                            <input type="email" class="form-control" id="email" required>
                            <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                        </div>
                    </div>

                </div>



                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <label class="form-label">Comments/Questions</label>
                        <textarea class="form-control" id="comments" rows="3"></textarea>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12">
                        <button type="submit" class="send-inquiry-btn">Send Inquiry</button>
                    </div>
                </div>
            </form>
        </div>


        <script>
            document.getElementById('bookingForm').addEventListener('submit', function (e) {
                e.preventDefault();

                // Gather form data
                const formData = {
                    arrivalDate: document.getElementById('arrivalDate').value,
                    departureDate: document.getElementById('departureDate').value,
                    name: document.getElementById('name').value,
                    guests: document.getElementById('guests').value,
                    children: document.getElementById('children').value,
                    email: document.getElementById('email').value,
                    phone: document.getElementById('countryCode').value + document.getElementById('phone').value,
                    comments: document.getElementById('comments').value
                };

                // Here you would typically send the data to your server
                // console.log('Form submitted:', formData);
                // alert('Booking inquiry sent successfully!');
                this.reset();
            });

            // Format phone number as user types
            document.getElementById('phone').addEventListener('input', function (e) {
                let x = e.target.value.replace(/\D/g, '').match(/(\d{0,3})(\d{0,3})(\d{0,4})/);
                e.target.value = !x[2] ? x[1] : '(' + x[1] + ') ' + x[2] + (x[3] ? '-' + x[3] : '');
            });

            // Set minimum date for arrival and departure
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('arrivalDate').min = today;

            document.getElementById('arrivalDate').addEventListener('change', function () {
                document.getElementById('departureDate').min = this.value;
                if (document.getElementById('departureDate').value && document.getElementById('departureDate').value < this.value) {
                    document.getElementById('departureDate').value = this.value;
                }
            });
        </script>

    </section>

    <section class="LuxuriousBeach LuxuriousBeach1 py-4">
        <div class="container">
            <div class="row g-0">
                <div class="col-12 col-lg-6">
                    <div class="hero-section2">
                        <div class="content-wrapper">
                            <h2 style="font-size: 20px; font-weight: 300; letter-spacing: 4px;">LUXURIOUS BALINESE STYLE
                            </h2>
                            <h1 class="main-title">MAYA KOBE HOUSE</h1>
                            <div class="description">
                                <p>Discover <i>Maya Kobe Cottage</i>, a charming 2-bedroom villa nestled within the tranquil grounds of <i>Maya Kobe</i> on Kilifi’s beautiful Bofa beach. This secluded retreat offers a luxurious yet private escape, surrounded by lush gardens and just steps from the Indian Ocean.</p>
                                <p>With Balinese-inspired architecture and locally crafted decor, <i>Maya Kobe Cottage</i> provides the ideal setting for a peaceful getaway, complete with its own private swimming pool and serene outdoor spaces.</p>
                                <p>For those traveling with larger groups, the property also features an expansive 4-bedroom villa, offering an enhanced experience of tropical luxury and coastal charm.
                                </p>
                            </div>
                            <div class="d-flex">
                                <a href="#" class="btn book-btn">Book an Enquiry →</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-6" style="min-height: 50vh">
                    <div class="slider-container">
                        <img src="images/Pool-Image_Edited.jpg.webp" alt="Luxury Villa 1" class="slider-image active">
                        <img src="images/a5-1.jpg.webp" alt="Luxury Villa 2" class="slider-image">
                        <img src="images/a3.jpg.webp" alt="Luxury Villa 3" class="slider-image">
                        <img src="images/a1.jpg.webp" alt="Luxury Villa 4" class="slider-image">
                        <img src="images/a2.jpg.webp" alt="Luxury Villa 5" class="slider-image">
                    </div>
                </div>
            </div>
        </div>

        <script>
            function initializeSlider() {
                const images = document.querySelectorAll('.LuxuriousBeach1 .slider-image');
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
            <h2 class="section-title">PREMIUM AMENITIES OFFERED AT OUR LUXURIOUS BALINESE STYLE BEACH HOUSE - MAYA KOBE
            </h2>

            <!-- <p class="text-left intro-text">
                With your stay at My Amani’s Garden View Room, you’ll enjoy the very best that luxury vacationing has to
                offer:
            </p> -->

            <div class="row">
                <div class="col-lg-6">
                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-umbrella-beach"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>
                                Direct beach access</h5>
                        </div>
                    </div>

                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-tree"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>
                                Lush tropical gardens within a 10 acre property</h5>
                        </div>
                    </div>

                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-water"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>20m beachfront swimming pool</h5>
                        </div>
                    </div>

                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-house-chimney"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>
                                Spacious gazebo overlooking the Indian Ocean</h5>
                        </div>
                    </div>

                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-spa"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>Private beach front massage huts</h5>
                        </div>
                    </div>

                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-bed"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>4 seaview en-suite bedrooms - sleeps 8</h5>
                        </div>
                    </div>

                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-bed-pulse"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>Separate 2 bedroom cottage - sleeps 4</h5>
                        </div>
                    </div>

                </div>

                <div class="col-lg-6">
                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-plate-wheat"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>
                                Large dining room</h5>
                        </div>
                    </div>
                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-couch"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>
                                Spacious living room</h5>
                        </div>
                    </div>
                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-utensils"></i>
                        </div>
                        <div class="amenity-text">
                            <h5> Self catering</h5>
                        </div>
                    </div>

                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-kitchen-set"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>
                                Fully equipped kitchen with a private chef available on request</h5>
                        </div>
                    </div>

                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-wifi"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>
                                Wi-Fi</h5>
                        </div>
                    </div>

                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-shield-halved"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>24 hour security</h5>
                        </div>
                    </div>

                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="amenity-text">
                            <h5>Check In: 2.00pm – Check Out: 10.00am</h5>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-left">
                <button class="book-btn">Book a Reservation Today →</button>
                <p class="fs-small mt-3 fw-bold" style="font-size: 12px">A security deposit of USD500 will apply – Non
                    smoking property<br>
                    Airport transfers available on request.</p>
            </div>


        </div>
    </section>

    <section class="LuxuriousBeach LuxuriousBeach2 py-4" style="background-color: #FFFAEF;">
        <div class="container">
            <h1 class="main-title1 text-center text-dark mb-4">PICTURE YOUR TOES IN THE
                VELVET SANDS OF BOFA BEACH</h1>
            <div class="row g-0">

                <div class="col-12 col-lg-6" style="align-self: center;">
                    <img src="images/SEC5.jpg.webp" alt="Luxury Villa 1" class="img-fluid">
                </div>

                <div class="col-12 col-lg-6">
                    <div class="hero-section2" style="background-color: #FFFAEF;">
                        <div class="content-wrapper">
                            <h1 class="main-title text-dark">THE LUXURY OF MAYA KOBE</h1>
                            <div class="description text-dark">
                                <p>Situated on the pristine Bofa beach in Kilifi, Kenya, overlooking the majestic Indian
                                    Ocean, lies Maya Kobe: a Balinese-inspired, luxurious four-bedroom beach house with
                                    a detached 2-bedroom cottage.</p>
                            </div>
                            <div class="d-flex">
                                <a href="#" class="btn book-btn">Book an Reservation Today →</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- <script>
            function initializeSlider2() {
                const images = document.querySelectorAll('.LuxuriousBeach2 .slider-image');
                let currentIndex = 0;

                function showNextImage() {
                    images[currentIndex].classList.remove('active');
                    currentIndex = (currentIndex + 1) % images.length;
                    images[currentIndex].classList.add('active');
                }

                setInterval(showNextImage, 3000);
            }

            document.addEventListener('DOMContentLoaded', initializeSlider2);
        </script> -->
    </section>

    <section class="LuxuriousBeach LuxuriousBeach3 py-4" style="background-color: #031B31;">
        <div class="container">
            <div class="row g-0">


                <div class="col-12 col-lg-6">
                    <div class="hero-section2" style="background-color: #031B31;">
                        <div class="content-wrapper">
                            <h1 class="main-title text-white" style="font-size: 24px;">THIS PICTURESQUE BEACH HOUSE PROPERTY OFFERS YOU:</h1>
                            <div class="description text-white">
                                <p><i>Maya Kobe Cottage</i>, a serene 2-bedroom villa within the <i>Maya Kobe</i> property, offers a peaceful, secluded retreat on Kilifi’s Bofa beach. With its own private swimming pool, lush gardens, and direct beach access, it provides the ultimate coastal escape. Enjoy Balinese-inspired architecture and luxury comforts, making this the perfect getaway for couples or small groups seeking tranquility. Book your stay today and discover why Kilifi is one of Kenya’s most coveted vacation destinations.</p>

                            </div>
                            <div class="d-flex">
                                <a href="#" class="btn book-btn">Book an Reservation Today →</a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-6" style="min-height: 50vh">
                    <div class="slider-container" style="left: 0px;">
                        <img src="images/SEC4.jpg.webp" alt="Luxury Villa 1" class="slider-image active">
                        <img src="images/SEC3.jpg.webp" alt="Luxury Villa 2" class="slider-image">
                        <img src="images/SEC2.jpg.webp" alt="Luxury Villa 3" class="slider-image">
                    </div>
                </div>

            </div>
        </div>

        <script>
            function initializeSlider3() {
                const images = document.querySelectorAll('.LuxuriousBeach3 .slider-image');
                let currentIndex = 0;

                function showNextImage() {
                    images[currentIndex].classList.remove('active');
                    currentIndex = (currentIndex + 1) % images.length;
                    images[currentIndex].classList.add('active');
                }

                setInterval(showNextImage, 3000);
            }

            document.addEventListener('DOMContentLoaded', initializeSlider3);
        </script>
    </section>

    <section class="LuxuriousBeach LuxuriousBeach2 py-4" style="background-color: #FFFAEF;">
        <div class="container">
            <div class="row g-0">

                <div class="col-12 col-lg-6" style="align-self: center;">
                    <img src="images/11143234_717034858402984_7093795122795029451_n.jpg.webp" alt="Luxury Villa 1"
                        class="img-fluid">
                </div>

                <div class="col-12 col-lg-6">
                    <div class="hero-section2" style="background-color: #FFFAEF;">
                        <div class="content-wrapper">
                            <h1 class="main-title text-dark">YOUR HOME BASE FOR ADVENTURE</h1>
                            <div class="description text-dark">
                                <p>A bounty of activities await the young at heart in Maya Kobe’s surrounding areas:</p>
                                <ul>
                                    <li>Extensive water sport options, ranging from kite surfing to snorkeling and scuba
                                        diving</li>
                                    <li>Sunset dhows and included dinner on the famous, nearby Kilifi Creek</li>
                                    <li>18-hole PGA-accredited golf course in the nearby vipingo ridge</li>
                                    <li>A plenitude of other options to keep you busy for as long as your heart desires
                                    </li>
                                </ul>
                            </div>
                            <div class="d-flex">
                                <a href="#" class="btn book-btn">Book an Reservation Today →</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- <script>
            function initializeSlider2() {
                const images = document.querySelectorAll('.LuxuriousBeach2 .slider-image');
                let currentIndex = 0;

                function showNextImage() {
                    images[currentIndex].classList.remove('active');
                    currentIndex = (currentIndex + 1) % images.length;
                    images[currentIndex].classList.add('active');
                }

                setInterval(showNextImage, 3000);
            }

            document.addEventListener('DOMContentLoaded', initializeSlider2);
        </script> -->
    </section>


    <style>
        .review-card {
            background-color: #fff;
            border-radius: 8px;
            padding: 2rem;
            max-width: 600px;
            margin: 2rem auto;
        }

        .review-text {
            font-style: italic;
            color: #333;
            font-size: 1.1rem;
            /* margin-bottom: 1.5rem; */
            font-family: "Piazzolla", Sans-serif;
            line-height: 1.6;
        }

        .reviewer-name {
            font-family: "Marcellus SC", Sans-serif;
            color: #000;
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
        }

        .verified-badge {
            color: #0066ff;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-family: poppins;
            background-color: #fff;
            padding: 10px;
            border-radius: 30px;
        }

        .location-date {
            color: #666;
            font-size: 0.9rem;
            text-align: center;
            margin-top: 1rem;
            font-family: "Roboto", Sans-serif;
        }
    </style>

    <section class="LuxuriousBeach LuxuriousBeach2 py-4" style="background-color: #FFFAEF;">
        <div class="container">
            <div class="row g-0">
                <div class="col-12 col-lg-6">
                    <div class="hero-section2" style="background-color: #FFFAEF;">
                        <div class="content-wrapper">
                            <h1 class="main-title text-dark" style="font-size: 24px;">EXPERIENCE A PARADISE LIKE NO
                                OTHER</h1>
                            <div class="review-card">
                                <p class="review-text">"Stunning place, great staff - friendly and attentive. They have
                                    made our visit perfect. Fabulous location, we will be coming back again and again.
                                    Thank you so much!"</p>
                            </div>
                            <div class="d-flex align-items-center justify-content-between">
                                <div class="reviewer-name">-Mr & Mrs B Vyas.</div>
                                <div class="verified-badge">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor"
                                        viewBox="0 0 16 16">
                                        <path
                                            d="M10.97 4.97a.75.75 0 0 1 1.07 1.05l-3.99 4.99a.75.75 0 0 1-1.08.02L4.324 8.384a.75.75 0 1 1 1.06-1.06l2.094 2.093 3.473-4.425a.267.267 0 0 1 .02-.022z" />
                                    </svg>
                                    Verified Review
                                </div>
                            </div>

                            <div class="location-date">
                                <div>UK</div>
                                <div>2 Aug 2023</div>
                            </div>

                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-6" style="align-self: center;">
                    <img src="images/Maya-kobe.png" alt="Luxury Villa 1" class="img-fluid">
                </div>

            </div>
        </div>

        <!-- <script>
            function initializeSlider2() {
                const images = document.querySelectorAll('.LuxuriousBeach2 .slider-image');
                let currentIndex = 0;

                function showNextImage() {
                    images[currentIndex].classList.remove('active');
                    currentIndex = (currentIndex + 1) % images.length;
                    images[currentIndex].classList.add('active');
                }

                setInterval(showNextImage, 3000);
            }

            document.addEventListener('DOMContentLoaded', initializeSlider2);
        </script> -->
    </section>


    <div class="slidbox">
        <div class="tourist-activities-section">
            <h2 class="display-4 section-heading">Adventure and Activities</h2>
            <div class="activities-carousel">
                <div class="activities-track">
                    <!-- Slides dynamically added -->
                </div>
                
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
                        <h4 class="adventure-subtitle">Book your room right now and start your luxurious stay at Maya
                            Kobe.</h4>
                        <a href="#" class="btn-reservation">
                            <span>Book a Reservation Today</span>
                            <i class="fas fa-long-arrow-alt-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include __DIR__ . '/includes/room-reviews.php'; ?>

    <section id="book" class="ts-booking-section" style="max-width:760px;margin:4rem auto;padding:0 1.5rem;">
      <h2 style="font-family:'Cormorant Garamond',serif;font-weight:300;text-align:center;margin-bottom:1.5rem;">Check availability &amp; request your dates</h2>
      <?php include __DIR__ . '/includes/booking-widget.php'; ?>
    </section>

    <?php include __DIR__ . '/includes/cross-sell-tours.php'; ?>

    <?php include 'includes/footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>

    <!-- IntlTelInput Script -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/intlTelInput.min.js"></script>
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
                image: 'images/kitesurf.jpeg',
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
                    <div class="activity-slide">
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
        $(document).ready(function () {
            // Handle click on parent dropdown toggle (including submenus)
            $('.dropdown-toggle').on('click', function (e) {
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
                $(this).attr('aria-expanded', function (_, attr) {
                    return attr === 'true' ? 'false' : 'true';
                });
            });

            // Close dropdowns when clicking outside
            $(document).on('click', function (e) {
                if (!$(e.target).closest('.dropdown').length) {
                    $('.dropdown-menu').removeClass('show');
                    $('.dropdown-toggle').attr('aria-expanded', 'false');
                }
            });

            // Prevent closing of the parent dropdown when clicking on a submenu toggle
            $('.dropdown-submenu > .dropdown-toggle').on('click', function (e) {
                e.stopPropagation(); // Prevent closing parent dropdown when clicking on submenu
            });
        });
    </script>

    <script>
        document.querySelectorAll('.custom-toggler').forEach(button => {
            button.addEventListener('click', function () {
                const expanded = this.getAttribute('aria-expanded') === 'true' || false;
                this.setAttribute('aria-expanded', !expanded);
            });
        });
    </script>
</body>

</html>