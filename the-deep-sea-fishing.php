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

    <!-- header section css -->
    <style>
        .hero-sections {
            position: relative;
            min-height: 100vh;
            overflow: hidden;
            font-family: Marcellus SC, sans-serif;
            font-size: 44.8px;
            font-weight: 100;
            letter-spacing: 6px;
            line-height: 44.8px;
            text-decoration: none soli rgb(255, 255, 255);
            text-transform: uppercase;
        }

        .slideshow-container {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
        }

        .slideshow-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('images/fisherman-4601744_1280.jpg');
            background-color: #f7f7f7;
            background-size: cover;
            background-position: center;
            animation: kenBurnsEffect 10s infinite alternate;
        }

        .overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            z-index: 2;
        }

        .content-container {
            position: relative;
            z-index: 3;
            color: white;
            text-align: center;
        }

        @keyframes kenBurnsEffect {
            from {
                transform: scale(1);
            }

            to {
                transform: scale(1.1);
            }
        }

        .main-title {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 1rem;
        }

        .subtitlee {
            font-family: Piazzolla, sans-serif;
            font-size: 22.4px;
            font-weight: 500;
            letter-spacing: 6px;
            line-height: 22.4px;
            text-decoration: none solid rgb(255, 255, 255);
            text-transform: capitalize;
        }
    </style>

    <!-- PGA GOLF CSS -->
    <style>
        .banner-section {
            background-color: #0A192F;
            color: white;
            padding: 100px 0;
            text-align: center;
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
    </style>

    <!-- driving range -->
    <style>
        .facility-section {
            background-color: #fffaef;
            padding: 40px;
        }

        .content-box {
            background-color: #0a192f;
            color: white;
            padding: 3rem 2rem;
            height: 100%;
            align-content: center;
        }

        .divider {
            width: 50px;
            height: 2px;
            background-color: #d4af37;
            margin: 1.5rem 0;
        }

        .facility-title {
            font-family: Marcellus SC, sans-serif;
            font-weight: 36px;
            font-weight: 300;
            letter-spacing: 4px;
            line-height: 36px;
            text-decoration: none solid rgb(255, 255, 255);
        }

        .subtitle {
            font-family: Piazzolla, sans-serif;
            font-size: 15px;
            font-weight: 300;
            letter-spacing: 0.7px;
            line-height: 22.5px;
            text-decoration: none solid rgb(255, 255, 255);
            margin-bottom: 1rem;
        }

        .facility-list {
            list-style: none;
            padding-left: 0;
        }

        .facility-list li {
            margin-bottom: 0.5rem;
            position: relative;
            padding-left: 1.5rem;
        }

        .facility-list li:before {
            content: "•";
            position: absolute;
            left: 0;
            color: #d4af37;
        }

        .facility-image {
            height: 369px !important;
            width: 604px !important;
            object-fit: cover;
            min-height: 400px;
        }

        @media (max-width: 768px) {
            .facility-image {
                min-height: 300px;
            }

            .content-box {
                padding: 2rem 1.5rem;
            }
        }
    </style>

    <!-- GOLF WITH A UNIQUELY KENYAN TWIST CSS -->
    <style>
        .golf-section {
            padding: 80px 0;
            background-color: #ffffff;
        }

        .divider {
            width: 80px;
            height: 3px;
            background-color: #c0a080;
            margin: 20px 0;
        }

        .heading {
            font-family: Marcellus SC, sans-serif;
            font-size: 36px;
            font-weight: 300;
            letter-spacing: 4px;
            line-height: 36px;
            text-decoration: none solid rgb(45, 45, 45);
        }

        .subheading {
            font-family: Piazzolla, sans-serif;
            font-size: 16px;
            font-weight: 500;
            letter-spacing: 2px;
            line-height: 24px;
            text-decoration: none solid rgb(45, 45, 45);
        }


        .golf-image {
            width: 100%;
            height: 400px;
            object-fit: cover;
        }
    </style>

    <!-- Ready to explore -->
    <style>
        .hero-section {
            position: relative;
            background-image: url('images/green-2480256_640.jpg');
            background-size: cover;
            background-position: center;
            height: 60vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 1;
        }

        .content-wrapper {
            position: relative;
            z-index: 2;
            text-align: center;
            max-width: 800px;
            width: 100%;
            padding: 2rem;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .hero-title {
            color: white;
            margin-bottom: 2.5rem;
            text-transform: uppercase;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
            width: 100%;
            text-align: center;
            font-family: Marcellus SC, sans-serif;
            font-size: 36px;
            font-weight: 500;
            letter-spacing: 1.6px;
            line-height: 46.8px;
            text-decoration: none solid rgb(255, 255, 255);
        }

        .cta-wrapper {
            width: 100%;
            text-align: center;
        }

        .cta-button {
            font-family: Piazzolla, sans-serif;
            background-color: #C5A572;
            color: #fff;
            padding: 12px 36px;
            border: none;
            border-radius: 5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            font-size: 20px;
            letter-spacing: 2px;
            text-decoration: none solid rgb(255, 255, 255);
            font-weight: 500;
            min-width: 300px;
            margin: 0 auto;
        }

        .cta-button:hover {
            transform: scale(0.96);
            background-color: #B89660;
            color: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            text-decoration: none;
        }

        .cta-button i {
            margin-left: 12px;
            font-size: 1.2rem;
        }

        /* Large screens */
        @media (min-width: 1400px) {
            .hero-title {
                font-size: 4rem;
            }

            .content-wrapper {
                max-width: 1000px;
            }

            .cta-button {
                padding: 20px 40px;
                font-size: 1.2rem;
            }
        }

        /* Medium screens */
        @media (max-width: 992px) {
            .hero-title {
                font-size: 3rem;
            }

            .content-wrapper {
                max-width: 700px;
            }
        }

        /* Small screens */
        @media (max-width: 768px) {
            .hero-section {
                height: 80vh;
            }

            .hero-title {
                font-size: 2.5rem;
                margin-bottom: 2rem;
            }

            .cta-button {
                padding: 15px 30px;
                min-width: 280px;
                font-size: 1rem;
            }
        }

        /* Extra small screens */
        @media (max-width: 576px) {
            .hero-section {
                height: 70vh;
            }

            .hero-title {
                font-size: 2rem;
                margin-bottom: 1.5rem;
            }

            .content-wrapper {
                padding: 1rem;
            }

            .cta-button {
                padding: 12px 24px;
                min-width: 260px;
            }
        }

        /* Handle very tall screens */
        @media (min-height: 1000px) {
            .hero-section {
                height: 60vh;
            }
        }

        /* Handle landscape orientation on mobile */
        @media (max-height: 600px) and (orientation: landscape) {
            .hero-section {
                height: 100vh;
                padding: 2rem 0;
            }

            .hero-title {
                font-size: 2rem;
                margin-bottom: 1rem;
            }
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

</head>

<body>
    <?php include 'includes/g-tag-body.php'; ?>

    <?php include 'includes/header.php'; ?>

    <section class="hero-sections d-flex align-items-center">
        <div class="slideshow-container">
            <div class="slideshow-image"></div>
        </div>
        <div class="overlay"></div>
        <div class="container content-container">
            <div class="row justify-content-center">
                <div class="col-12 col-md-10 col-lg-8">
                    <h1 class="main-title">EXPERIENCE LIFE ON THE SEA</h1>
                    <p class="subtitlee">Kenyan Deep Sea Fishing</p>
                </div>
            </div>
        </div>
    </section>

    <section class="banner-section">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-12 col-md-10 col-lg-8">
                    <h1 class="banner-title">A REEL DEEP SEA EXPERIENCE</h1>
                    <p class="banner-description">Head out offshore to go deep sea fishing with the experienced local fishermen of Watamu. Your guides will lead you to the rich fishing grounds outside the reef and will provide you with all the tools and guidance you need to reel ‘em in.</p>
                </div>
            </div>
        </div>
    </section>

    <style>
        .facility-section .slider-container{
            height: 100%;
            position: relative;
            overflow: hidden;
        }
        @media (max-width: 768px) {
            .facility-section .slider-container {
                height: 100%;
            }
        }
        .facility-section .slider-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            transition: opacity 1s ease-in-out;
            object-fit: cover;
        }

        .facility-section .slider-image.active {
            opacity: 1;
        }
    </style>


    <section class="facility-section">
        <div class="container-fluid p-0">
            <div class="row g-0">
                <!-- Content Column -->
                <div class="col-12 col-lg-6">
                    <div class="content-box">
                        <h3 class="facility-title">SUSTAINABLE FISHING PRACTICES</h3>
                        <div class="divider"></div>
                        <p class="subtitle">Merge Fun and Ecological Responsibility</p>
                        <p class="mb-4 subtitle">Not only do sustainable fishing practices protect the environment, but they preserve the livelihood of the local people groups who depend on fishing. Your deep sea fishing guides will make sure that your experience takes care of the environment and leaves you with great memories and a full stomach to boot.</p>
                    </div>
                </div>

                <!-- Image Column -->
                <div class="col-12 col-lg-6" style="min-height: 50vh">
                    <div class="slider-container">
                        <img src="images/7002cd_979d4c6d627f42988a34d6baefbacbf4mv2.avif" alt="image 1" class="slider-image active">
                        <img src="images/beautiful-sunset-landscape-with-fishing-rod_198523-25.jpg" alt="image 2" class="slider-image">
                        <img src="images/deep-sea-fishing-kenya-scaled.jpg" alt="image 3" class="slider-image">
                        <img src="images/Deep-sea-fishing-img-1 (1).jpg" alt="image 4" class="slider-image">
                    </div>
                </div>
            </div>
        </div>

        <script>
            function initializeSlider() {
                const images = document.querySelectorAll('.facility-section .slider-image');
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

    <section class="hero-section">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-12 col-md-10 col-lg-8">
                    <div class="content-wrapper">
                        <h2 class="hero-title">Ready to explore<br>all this and more?</h2>
                        <a href="contact.php" class="cta-button">
                            Start Your Kenyan Adventure Today
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js">
    </script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/glightbox/3.2.0/js/glightbox.min.js"></script>



    <!-- Gallery -->
    <script>
        // Initialize GLightbox
        const lightbox = GLightbox({
            touchNavigation: true,
            loop: true,
            autoplayVideos: true,
            preload: false
        });
    </script>
    <!-- Gallery -->

    <!-- Slider script -->
    <script>
        document.addEventListener("DOMContentLoaded", () => {
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

            class Carousel {
                constructor(container, slides) {
                    this.container = container;
                    this.track = container.querySelector('.activities-track');
                    this.slides = slides;
                    this.currentIndex = 0;

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
                    document.querySelector('.carousel-button-next').addEventListener('click', () => {
                        this.moveSlide(1);
                    });

                    document.querySelector('.carousel-button-prev').addEventListener('click', () => {
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
                        } else if (this.currentIndex === totalSlides - this.slidesPerView) {
                            this.currentIndex = this.slidesPerView;
                            this.updatePosition(false);
                        }
                    }, 500); // Match transition duration
                }

                updatePosition(animate = true) {
                    if (!animate) this.track.style.transition = 'none';
                    const offset = -this.currentIndex * (100 / this.slidesPerView);
                    this.track.style.transform = `translateX(${offset}%)`;
                    if (!animate) setTimeout(() => this.track.style.transition = 'transform 0.5s ease', 0);
                }
            }

            new Carousel(document.querySelector('.activities-carousel'), slidesData);
        });
    </script>
    <!-- Slider script -->

    
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