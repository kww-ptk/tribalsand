<?php require_once __DIR__ . '/includes/db.php'; // asset_url() for the og:image below ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Kenya Active Holidays | Tribal Sand</title>
    <meta name="description"
        content="Discover why Kenya is the perfect destination for active travellers. Golf, kitesurfing, scuba diving and luxury coastal living.">
    <link rel="canonical" href="https://tribalsand.com/kenya-active-holidays">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Tribal Sand">
    <meta property="og:url" content="https://tribalsand.com/kenya-active-holidays">
    <meta property="og:title" content="Kenya Active Holidays · Golf, Kitesurf &amp; Diving · Tribal Sand">
    <meta property="og:description" content="Kenya for active travellers — golf, kitesurfing, scuba diving and luxury coastal living on the North Coast.">
    <meta property="og:image" content="<?= asset_url('images/Maya-Kobe-1-hero.webp') ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Kenya Active Holidays · Golf, Kitesurf &amp; Diving · Tribal Sand">
    <meta name="twitter:description" content="Kenya for active travellers — golf, kitesurfing, scuba diving and luxury coastal living on the North Coast.">
    <meta name="twitter:image" content="<?= asset_url('images/Maya-Kobe-1-hero.webp') ?>">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

    <style>
    body {
        margin: 0;
        padding: 0;
    }

    header {
        position: absolute;
        top: 0;
        width: 100%;
        z-index: 1000;
    }

    .hero-section {
        position: relative;
        height: 90vh;
        background: url('images/hero-kenya-coast.jpg') center center/cover no-repeat;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .hero-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(to right,
                rgba(210, 120, 80, 0.45),
                rgba(255, 140, 60, 0.55));
    }

    .hero-content {
        position: relative;
        z-index: 2;
        text-align: center;
        color: #fff;
        max-width: 900px;
    }

    .hero-title {
        font-size: 64px;
        font-weight: 600;
        line-height: 1.2;
        font-family: 'Georgia', serif;
    }

    .hero-subtitle {
        font-size: 20px;
        margin-top: 20px;   
        font-style: italic;
        opacity: 0.9;
        max-width: 650px;
        margin-left: auto;
        margin-right: auto;
    }

    .top-bar {
        position: absolute;
        top: 0;
        width: 100%;
        z-index: 3;

    }

    .brand {
        font-size: 26px;
        font-weight: 600;
        color: #fff;
        font-family: 'Georgia', serif;
    }

    .tagline {
        font-size: 14px;
        letter-spacing: 2px;
        text-transform: uppercase;
        color: #fff;
    }

    @media (max-width:768px) {

        .hero-title {
            font-size: 40px;
        }

        .hero-subtitle {
            font-size: 16px;
        }

    }

    .kenya-section {
        background: #f3f1ee;
        padding: 100px 20px;
    }

    .section-title {
        font-family: Georgia, serif;
        font-size: 48px;
        font-weight: 600;
        color: #1c2a3a;
        margin-bottom: 20px;
    }

    .title-divider {
        width: 60px;
        height: 3px;
        background: #d8793d;
        margin: 0 auto 30px auto;
    }

    .section-text {
        font-size: 18px;
        line-height: 1.8;
        color: #5c6b7a;
        margin-bottom: 18px;
    }

    .highlight {
        font-weight: 500;
        color: #2c3e50;
    }

    .golf-section {
        background: #f3f1ee;
        padding: 100px 20px;
    }

    .section-title {
        font-family: Georgia, serif;
        font-size: 44px;
        font-weight: 600;
        color: #1c2a3a;
        margin-bottom: 15px;
    }

    .title-divider {
        width: 60px;
        height: 3px;
        background: #d8793d;
        margin: 0 auto 30px auto;
    }

    .golf-img {
        border-radius: 8px;
        width: 100%;
        height: 320px;
        object-fit: cover;
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
    }

    .section-text {
        font-size: 17px;
        line-height: 1.8;
        color: #5c6b7a;
        margin-bottom: 20px;
        text-align: center;
    }

    .why-title {
        font-family: Georgia, serif;
        font-size: 22px;
        margin-top: 20px;
        margin-bottom: 10px;
        color: #1c2a3a;
        text-align: center;
    }

    .golf-list {
        list-style: none;
        padding: 0;
        text-align: center;
    }

    .golf-list li {
        margin: 8px 0;
        font-size: 16px;
        color: #5c6b7a;
        position: relative;
    }

    .golf-list li:before {
        content: "•";
        color: #d8793d;
        font-size: 20px;
        margin-right: 8px;
    }

    .kite-section {
        background: #f3f1ee;
        padding: 100px 20px;
    }

    .section-title-left {
        font-family: Georgia, serif;
        font-size: 42px;
        font-weight: 600;
        color: #1c2a3a;
    }

    .title-divider-left {
        width: 60px;
        height: 3px;
        background: #d8793d;
        margin: 15px 0 25px 0;
    }

    .section-text-left {
        font-size: 17px;
        line-height: 1.8;
        color: #5c6b7a;
        margin-bottom: 18px;
    }

    .conditions-title {
        font-family: Georgia, serif;
        font-size: 22px;
        margin-top: 10px;
        margin-bottom: 10px;
        color: #1c2a3a;
    }

    .kite-list {
        padding-left: 20px;
        margin-bottom: 20px;
    }

    .kite-list li {
        margin-bottom: 8px;
        color: #5c6b7a;
        font-size: 16px;
    }

    .kite-img {
        border-radius: 8px;
        height: 320px;
        width: 100%;
        object-fit: cover;
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
    }

    @media (max-width:991px) {

        .section-title-left {
            font-size: 34px;
        }

        .kite-img {
            margin-top: 30px;
            height: 280px;
        }

    }

    /* Custom Background Color */
    .bg-light-cream {
        background-color: #f9f7f2;
        color: #2c3e50;
    }

    /* Accent Line under Header */
    .header-line {
        width: 40px;
        height: 2px;
        background-color: #d1a47b;
        /* Muted orange/gold */
    }

    /* Image Styling */
    .rounded-custom {
        border-radius: 12px;
        height: 250px;
        /* Fixed height for uniformity */
        width: 100%;
        object-fit: cover;
    }

    /* Custom Bullet Points */
    .custom-list li {
        position: relative;
        padding-left: 20px;
        margin-bottom: 8px;
        font-size: 0.95rem;
    }

    .custom-list li::before {
        content: "•";
        color: #5d8aa8;
        /* Muted blue for bullets */
        position: absolute;
        left: 0;
        font-weight: bold;
    }

    /* Paragraph styling */
    p {
        font-size: 0.9rem;
        max-width: 650px;
    }


    .adventure-section {
        background-color: #fcfbf9;
        /* Subtle off-white background */
        font-family: 'Source Sans Pro', sans-serif;
    }

    .serif-font {
        font-family: 'Playfair Display', serif;
        color: #0d2137;
        /* Dark navy color */
        line-height: 1.2;
    }

    /* The exact orange line from the image */
    .orange-divider {
        width: 40px;
        height: 2px;
        background-color: #e67e22;
    }

    /* Bullet styling */
    .custom-bullet {
        color: #6c757d;
        font-size: 0.95rem;
        margin-bottom: 10px;
        padding-left: 20px;
        position: relative;
    }

    .custom-bullet::before {
        content: "•";
        color: #e67e22;
        position: absolute;
        left: 0;
        font-weight: bold;
    }

    /* Description text color and sizing */
    .description-text {
        color: #6c757d;
        font-size: 1rem;
        line-height: 1.6;
        max-width: 450px;
    }

    /* Rounded corners for the image */
    .rounded-3 {
        border-radius: 12px !important;
    }

    @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Source+Sans+Pro&display=swap');

    .comparison-section {
        background-color: #f8f6f2;
        /* Light cream background */
        font-family: 'Source Sans Pro', sans-serif;
    }

    .serif-font {
        font-family: 'Playfair Display', serif;
        color: #0d2137;
    }

    .orange-divider {
        width: 40px;
        height: 2px;
        background-color: #e67e22;
    }

    /* Card Styling */
    .custom-card {
        border: 1px solid #eee !important;
        border-radius: 8px;
        background-color: #ffffff;
    }

    /* Orange Sub-headers */
    .label-orange {
        color: #e67e22;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 1px;
        margin-bottom: 0.75rem;
    }

    /* List spacing */
    ul li {
        margin-bottom: 2px;
    }

    /* Italic footer text color */
    .fst-italic {
        color: #6c757d !important;
    }

    @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Source+Sans+Pro&display=swap');

    .schedule-section {
        background-color: #fcfbf9;
        font-family: 'Source Sans Pro', sans-serif;
    }

    .serif-font {
        font-family: 'Playfair Display', serif;
        color: #0d2137;
        max-width: 500px;
        margin: 0 auto;
    }

    .orange-divider {
        width: 40px;
        height: 2px;
        background-color: #e67e22;
    }

    /* Schedule Container sizing */
    .schedule-container {
        max-width: 700px;
        margin-top: 60px;
    }

    /* Time Label Styling */
    .schedule-time {
        color: #e67e22;
        font-weight: 500;
        font-size: 1.1rem;
    }

    /* Activity Styling */
    .schedule-activity {
        color: #6c757d;
        font-size: 1.1rem;
    }

    /* Timeline Visuals */
    .schedule-dot {
        width: 10px;
        height: 10px;
        background-color: #e67e22;
        border-radius: 50%;
        display: inline-block;
        z-index: 2;
        position: relative;
    }

    .vertical-line {
        position: absolute;
        width: 1px;
        background-color: #eee;
        height: 50px;
        /* Adjust based on your row height */
        top: 50%;
        left: 50%;
        transform: translateX(-50%);
        z-index: 1;
    }

    /* Note: Link Bootstrap Icons in your <head>: 
   <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css"> */

    .bg-light-cream {
        background-color: #fcfbf9;
    }

    .serif-font {
        font-family: 'Playfair Display', serif;
        color: #0d2137;
    }

    .orange-divider {
        width: 40px;
        height: 2px;
        background-color: #e67e22;
    }

    /* Icon Styling */
    .icon-circle {
        width: 60px;
        height: 60px;
        background-color: #f1ede4;
        /* Light tan background */
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .icon-circle i {
        color: #d1a47b;
        /* Muted copper/gold icon color */
        font-size: 1.5rem;
    }

    /* Final Thought Spacing */
    .border-top-custom {
        border-top: 1px solid #eee;
    }

    .cta-text {
        font-size: 1.25rem;
        line-height: 1.4;
    }

    .text-orange {
        color: #e67e22;
    }

    p.text-muted {
        font-size: 0.95rem;
        line-height: 1.6;
    }
    footer p {
        max-width: 100%;
    }    
    </style>

    <!-- <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css"> -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.1/font/bootstrap-icons.min.css"
        rel="stylesheet">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/css/intlTelInput.css">



</head>

<body>

    <?php include 'includes/header.php'; ?>
    <section class="hero-section">

        <div class="hero-overlay"></div>

        <!-- <div class="top-bar container d-flex justify-content-between">
            <div class="brand">Tribal Sand</div>
            <div class="tagline">Luxury Coastal Experiences</div>
        </div> -->

        <div class="hero-content container">

            <h1 class="hero-title">
                Kenya Is the Perfect Holiday Destination for Active Travellers
            </h1>

            <p class="hero-subtitle">
                Golf, Kitesurfing, Scuba Diving and Luxury Coastal Living
            </p>

        </div>

    </section>
    <section class="kenya-section">
        <div class="container">

            <div class="row justify-content-center">
                <div class="col-lg-8 text-center">

                    <h2 class="section-title">Kenya Is More Than Safari</h2>

                    <div class="title-divider"></div>

                    <p class="section-text">
                        When most travelers think of Kenya, they picture wildlife and savannah landscapes.
                    </p>

                    <p class="section-text">
                        But beyond safari, Kenya is one of Africa's most diverse destinations for active holidays.
                    </p>

                    <p class="section-text">
                        Along the Indian Ocean coastline, adventure and luxury exist side by side. You can spend the
                        morning on the water, the afternoon on a championship golf course, and the evening enjoying
                        chef-prepared coastal cuisine.
                    </p>

                    <p class="section-text highlight">
                        For travelers who want movement, energy, and refined comfort, Kenya delivers.
                    </p>

                </div>
            </div>

        </div>
    </section>
    <section class="golf-section">
        <div class="container">

            <div class="row justify-content-center">
                <div class="col-lg-10 text-center">

                    <h2 class="section-title">Golf on the Kenya Coast</h2>
                    <div class="title-divider"></div>

                </div>
            </div>

            <div class="row g-4 justify-content-center mt-3">

                <div class="col-md-5">
                    <img src="images/golf-zebras.jpeg" class="img-fluid golf-img" alt="Vipingo Ridge Golf Kenya">
                </div>

                <div class="col-md-5">
                    <img src="images/golf-courses.png" class="img-fluid golf-img" alt="Golf Course Sunset">
                </div>

            </div>

            <div class="row justify-content-center mt-4">
                <div class="col-lg-7">

                    <p class="section-text">
                        Kenya's coastline is home to one of East Africa's most impressive championship courses at
                        Vipingo Ridge.
                    </p>

                    <h5 class="why-title">Why It Stands Out</h5>

                    <ul class="golf-list">
                        <li>18-hole championship course</li>
                        <li>Elevated ocean views</li>
                        <li>Year-round playing conditions</li>
                        <li>International tournament standard layout</li>
                    </ul>

                    <p class="section-text">
                        For golf-focused travelers, Vipingo offers technical challenge and spectacular scenery in equal
                        measure. After a morning round, you return to your beachfront retreat to cool off in the pool or
                        relax by the ocean.
                    </p>

                </div>
            </div>

        </div>
    </section>

    <section class="kite-section">
        <div class="container">

            <div class="row align-items-center">

                <div class="col-lg-6">

                    <h2 class="section-title-left">
                        Kitesurfing in Watamu<br>or Kilifi
                    </h2>

                    <div class="title-divider-left"></div>

                    <p class="section-text-left">
                        Watamu is one of East Africa's best kitesurfing destinations, attracting riders from around the
                        world to its warm, turquoise waters and consistent trade winds.
                    </p>

                    <h5 class="conditions-title">Conditions</h5>

                    <ul class="kite-list">
                        <li>Wide shallow lagoons</li>
                        <li>Consistent seasonal winds</li>
                        <li>Warm Indian Ocean waters</li>
                        <li>Suitable for beginners and advanced riders</li>
                    </ul>

                    <p class="section-text-left">
                        The reef protects flat water areas perfect for beginners, while advanced riders can explore the
                        outside sections for bigger swells and open-ocean riding.
                    </p>

                </div>

                <div class="col-lg-6 text-center">

                    <img src="images/kitesurfing-watamu.jpg" class="img-fluid kite-img" alt="Kitesurfing Watamu Kenya">

                </div>

            </div>

        </div>
    </section>
    <section class="container py-5 text-center">
        <h1 class="display-5 fw-bold mb-3">Scuba Diving and Marine Exploration</h1>
        <div class="header-line mx-auto mb-5"></div>

        <div class="row g-4 mb-5">
            <div class="col-md-4">
                <img src="images/marine-park.jpg" class="img-fluid rounded-custom shadow-sm" alt="Coral Reef">
            </div>
            <div class="col-md-4">
                <img src="images/scuba-diving.jpg" class="img-fluid rounded-custom shadow-sm" alt="Diver and Fish">
            </div>
            <div class="col-md-4">
                <img src="images/deep-sea-fishing.jpg" class="img-fluid rounded-custom shadow-sm"
                    alt="Diver in blue water">
            </div>
        </div>

        <div class="row justify-content-center text-start">
            <div class="col-lg-8">
                <h3 class="h5 fw-bold mb-3">Watamu Marine Park Offers</h3>
                <ul class="list-unstyled custom-list mb-4">
                    <li>Coral reefs</li>
                    <li>Tropical reef fish</li>
                    <li>Sea turtles and dolphins</li>
                    <li>Warm year-round water temperatures</li>
                </ul>
                <p class="text-muted lh-lg">
                    Whether you're a certified diver exploring deeper reefs or a first-time snorkeler wading
                    into the shallows, Watamu's protected marine park delivers unforgettable underwater
                    encounters.
                </p>
            </div>
        </div>
    </section>
    <section class="adventure-section py-5">
        <div class="container">
            <div class="row align-items-start g-5">
                <div class="col-md-6">
                    <img src="images/kilifi-creek.jpg" alt="Kilifi Kayaking" class="img-fluid rounded-3">
                </div>

                <div class="col-md-6">
                    <div class="content-wrapper">
                        <h2 class="display-6 serif-font fw-bold mb-2">Kilifi and Water-Based Adventure</h2>
                        <div class="orange-divider mb-4"></div>

                        <ul class="list-unstyled mb-4">
                            <li class="custom-bullet">Paddle boarding</li>
                            <li class="custom-bullet">Kayaking on Kilifi Creek</li>
                            <li class="custom-bullet">Dhow sunset cruises</li>
                            <li class="custom-bullet">Deep sea fishing</li>
                        </ul>

                        <p class="description-text">
                            Kilifi is peaceful, spacious, and uncrowded — a destination
                            where the pace slows down but the adventure never stops. It's
                            the ideal complement to the energy of Watamu.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <section class="comparison-section py-5 px-3">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="serif-font display-6 fw-bold">Where to Stay: Boutique Hotel or Private Villa?</h2>
                <div class="orange-divider mx-auto mt-3"></div>
            </div>

            <div class="row g-4 justify-content-center">
                <div class="col-md-5">
                    <div class="card h-100 border-light shadow-sm custom-card">
                        <div class="card-body p-4 p-lg-5">
                            <h3 class="serif-font h4 fw-bold mb-4">Boutique Hotel Experience</h3>

                            <div class="mb-4">
                                <h6 class="label-orange">AVAILABLE AT</h6>
                                <p class="text-muted small">Maya Kobe • Zuri</p>
                            </div>

                            <div class="mb-4">
                                <h6 class="label-orange">BEST FOR</h6>
                                <ul class="list-unstyled text-muted small lh-lg">
                                    <li>Active couples</li>
                                    <li>Honeymooners</li>
                                    <li>Safari + coast travellers</li>
                                </ul>
                            </div>

                            <div>
                                <h6 class="label-orange">FEATURES</h6>
                                <ul class="list-unstyled text-muted small lh-lg">
                                    <li>Chef-prepared meals</li>
                                    <li>Beachfront dining</li>
                                    <li>Full-service hospitality</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-5">
                    <div class="card h-100 border-light shadow-sm custom-card">
                        <div class="card-body p-4 p-lg-5">
                            <h3 class="serif-font h4 fw-bold mb-4">Private Villa Experience</h3>

                            <div class="mb-4">
                                <h6 class="label-orange">BEST FOR</h6>
                                <ul class="list-unstyled text-muted small lh-lg">
                                    <li>Groups of friends</li>
                                    <li>Golf retreats</li>
                                    <li>Family adventure trips</li>
                                </ul>
                            </div>

                            <div>
                                <h6 class="label-orange">FEATURES</h6>
                                <ul class="list-unstyled text-muted small lh-lg">
                                    <li>Private pools</li>
                                    <li>Flexible catering</li>
                                    <li>Total privacy</li>
                                    <li>Space to relax</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-center mt-5">
                <p class="fst-italic text-muted small mx-auto" style="max-width: 500px;">
                    Tribal Sand is committed to sustainability and curated experiences — every stay is designed around
                    your pace.
                </p>
            </div>
        </div>
    </section>
    <section class="schedule-section py-5 px-3">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="serif-font display-6 fw-bold">The Perfect Balance: Energy and Ease</h2>
                <div class="orange-divider mx-auto mt-3"></div>
            </div>

            <div class="schedule-container mx-auto">
                <div class="row align-items-center mb-4">
                    <div class="col-5 text-end">
                        <span class="schedule-time">Morning</span>
                    </div>
                    <div class="col-2 text-center position-relative">
                        <div class="schedule-dot"></div>
                        <div class="vertical-line"></div>
                    </div>
                    <div class="col-5 text-start">
                        <span class="schedule-activity">Golf overlooking the ocean</span>
                    </div>
                </div>

                <div class="row align-items-center mb-4">
                    <div class="col-5 text-end">
                        <span class="schedule-time">Midday</span>
                    </div>
                    <div class="col-2 text-center position-relative">
                        <div class="schedule-dot"></div>
                        <div class="vertical-line"></div>
                    </div>
                    <div class="col-5 text-start">
                        <span class="schedule-activity">Kitesurfing in warm lagoon waters</span>
                    </div>
                </div>

                <div class="row align-items-center mb-4">
                    <div class="col-5 text-end">
                        <span class="schedule-time">Afternoon</span>
                    </div>
                    <div class="col-2 text-center position-relative">
                        <div class="schedule-dot"></div>
                        <div class="vertical-line"></div>
                    </div>
                    <div class="col-5 text-start">
                        <span class="schedule-activity">Swimming in your private pool</span>
                    </div>
                </div>

                <div class="row align-items-center">
                    <div class="col-5 text-end">
                        <span class="schedule-time">Evening</span>
                    </div>
                    <div class="col-2 text-center position-relative">
                        <div class="schedule-dot"></div>
                    </div>
                    <div class="col-5 text-start">
                        <span class="schedule-activity">Seafood dinner by candlelight</span>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <section class="py-5 bg-light-cream text-center">
        <div class="container mb-5">
            <h2 class="serif-font display-6 fw-bold">Who This Is For</h2>
            <div class="orange-divider mx-auto mt-3 mb-5"></div>

            <div class="row g-4 justify-content-center">
                <div class="col-md-4">
                    <div class="icon-circle mx-auto mb-3">
                        <i class="bi bi-heart"></i>
                    </div>
                    <h4 class="serif-font h5 fw-bold">Adventure Honeymooners</h4>
                    <p class="text-muted small">Couples who want more than sunbeds.</p>
                </div>
                <div class="col-md-4">
                    <div class="icon-circle mx-auto mb-3">
                        <i class="bi bi-compass"></i>
                    </div>
                    <h4 class="serif-font h5 fw-bold">Sports-Focused Travelers</h4>
                    <p class="text-muted small">Golfers, divers, and watersports lovers.</p>
                </div>
                <div class="col-md-4">
                    <div class="icon-circle mx-auto mb-3">
                        <i class="bi bi-people"></i>
                    </div>
                    <h4 class="serif-font h5 fw-bold">Corporate or Group Retreats</h4>
                    <p class="text-muted small">Activity-driven programs paired with luxury accommodation.</p>
                </div>
            </div>
        </div>

        <div class="container py-5 mt-5 border-top-custom">
            <h2 class="serif-font display-6 fw-bold">Final Thought</h2>
            <div class="orange-divider mx-auto mt-3 mb-4"></div>

            <div class="mx-auto" style="max-width: 700px;">
                <p class="text-muted mb-4">
                    Kenya is not only about safari. It is one of Africa's most dynamic active destinations.
                </p>
                <p class="text-muted mb-5">
                    When you combine world-class wildlife with Indian Ocean adventure and refined beachfront stays, you
                    create a holiday that moves your body and restores your mind.
                </p>

                <div class="cta-text fw-bold">
                    <p class="mb-1 text-dark">Stay active.</p>
                    <p class="mb-1 text-dark">Stay inspired.</p>
                    <p class="text-orange">Stay on the Kenya coast.</p>
                </div>
            </div>
        </div>
    </section>


    <?php include 'includes/footer.php'; ?>


    <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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

    <!--  -->
</body>

</html>