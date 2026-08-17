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
        /* Hero Section */
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

        .slideshow-image.active {
            opacity: 1;
        }

        /* Container */
        .container {
            position: relative;
            z-index: 2;
        }

        /* Heading */
        h1.display-4 {
            color: #ffffff;
            font-family: 'Montserrat', Sans-serif;
            font-size: 62px;
            font-weight: 300;
            text-transform: uppercase;
            line-height: 1.2;
            margin-bottom: 4rem;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            h1.display-4 {
                font-size: 42px;
                line-height: 1.4;
                margin-bottom: 2rem;
            }

            .hero-section {
                min-height: 50vh;
            }
        }
    </style>

    <!-- cards CSS -->
    <style>
        .detailscon {
            background-color: #fff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 20px 0;
        }

        .card {
            background-color: #001529;
            border-radius: 25px;
            overflow: hidden;
            border: none;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            height: 100%;
            position: relative;
        }

        .card:hover {
            transform: translateY(-5px);
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: 25px;
            pointer-events: none;
        }

        .img-wrapper {
            position: relative;
            height: 280px;
            overflow: hidden;
            padding: 10px;
        }

        .card-img-top {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 25px 25px 0 0;
        }

        .card-body {
            padding: 2rem 1.5rem;
            text-align: center;
        }

        .card-title {
            color: white;
            margin-bottom: 1.5rem;
            font-family: Montserrat, sans-serif;
            font-size: 36px;
            font-weight: 300;
            line-height: 36px;
            text-decoration: none solid rgb(247, 247, 243);
        }

        .btn-learn {
            color: white;
            background-color: transparent;
            border: 2px solid rgba(255, 255, 255, 0.8);
            border-radius: 10px;
            padding: 12px 24px 12px 24px;
            transition: all 0.3s ease;
            text-decoration: none solid rgb(66, 84, 99);
            display: inline-block;
            font-family: Montserrat, sans-serif;
            font-size: 18px;
            font-weight: 300;
            line-height: 18px;
        }

        .btn-learn:hover {
            background-color: #f7f7f3;
            color: #000;
            border-color: white;
            text-decoration: none;
        }

        .containclass {
            max-width: 1200px;
            padding:50px 30px;
            background-color: #FFF5E6;
        }

        @media (max-width: 991px) {
            .card {
                margin-bottom: 30px;
            }
        }

        @media (max-width: 767px) {
            .card-title {
                font-size: 1.8rem;
            }
            
            .img-wrapper {
                height: 240px;
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

    <!-- Hero Section -->
    <section class="hero-section">
        <!-- Slideshow images -->
        <div class="slideshow-image active" style="
          background-image: url('images/reservation-1.jpg');
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
                        Reservation
                    </h1>
                    <!-- Downward arrow icon with bounce animation -->
                    <i class="fas fa-angle-down fa-2x bounce"></i>
                </div>
            </div>
        </div>
    </section>


    <div class="detailscon">
      <div class="container containclass">
        <div class="row g-4">
          <!-- My Amani Excursions -->
          <div class="col-lg-4 col-md-6">
            <div class="card">
              <div class="img-wrapper">
                <img
                  src="<?= asset_url('wp-content/uploads/2024/05/Copy-of-6.jpg.webp') ?>"
                  class="card-img-top"
                  alt="My Amani Excursions"
                />
              </div>
              <div class="card-body">
                <h2 class="card-title">My Amani<br />Excursions</h2>
                <a
                  href="https://docs.google.com/viewerng/viewer?url=<?= asset_url('wp-content/uploads/2024/05/TRIBAL-SAND-My-Amani-Excursions.pdf') ?>"
                  class="btn-learn"
                >
                  Learn More
                </a>
              </div>
            </div>
          </div>

          <!-- Maya Kobe Excursions -->
          <div class="col-lg-4 col-md-6">
            <div class="card">
              <div class="img-wrapper">
                <img
                  src="<?= asset_url('wp-content/uploads/2024/05/Copy-of-5.jpg.webp') ?>"
                  class="card-img-top"
                  alt="Maya Kobe Excursions"
                />
              </div>
              <div class="card-body">
                <h2 class="card-title">Maya Kobe<br />Excursions</h2>
                <a
                  href="https://docs.google.com/viewerng/viewer?url=<?= asset_url('wp-content/uploads/2024/05/TRIBAL-SAND-Maya-Kobe-Excursions.pdf') ?>"
                  class="btn-learn"
                >
                  Learn More
                </a>
              </div>
            </div>
          </div>

          <!-- Zuri Excursions -->
          <div class="col-lg-4 col-md-6">
            <div class="card">
              <div class="img-wrapper">
                <img
                  src="<?= asset_url('wp-content/uploads/2024/05/Copy-of-3.jpg.webp') ?>"
                  class="card-img-top"
                  alt="Zuri Excursions"
                />
              </div>
              <div class="card-body">
                <h2 class="card-title">Zuri<br />Excursions</h2>
                <a
                  href="https://docs.google.com/viewerng/viewer?url=<?= asset_url('wp-content/uploads/2024/05/TRIBAL-SAND-Zuri-Excursions.pdf') ?>"
                  class="btn-learn"
                >
                  Learn More
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    

    <section class="adventure-section">
        <div class="container">
            <div class="row">
                <div class="col-md-8 offset-md-2">
                    <div class="adventure-content">
                        <h2 class="adventure-title">Make Room<br> for Adventure</h2>
                        <h4 class="adventure-subtitle">Book your room right now and start your amazing adventure full of
                            discoveries and new experiences with My Amani.</h4>
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
        document.addEventListener('DOMContentLoaded', () => {
            const slidesData = [{
                    image: 'images/Kite-Surfing.jpg',
                    title: 'Dhow Cruise',
                    description: 'Magical sunset along Kilifi Creek',
                    alt: 'Dhow'
                },
                {
                    image: 'images/Kuruwitu-Conservancy.jpg',
                    title: 'Beach Fun',
                    description: 'Water sports on pristine beaches',
                    alt: 'Beach'
                },
                {
                    image: 'images/My-Amani-8.jpg',
                    title: 'Snorkeling',
                    description: 'Explore vibrant marine life',
                    alt: 'Snorkeling'
                },
                {
                    image: 'images/Dolphin-sighting.jpg',
                    title: 'Safari',
                    description: 'Wildlife in natural habitat',
                    alt: 'Safari'
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

</body>

</html>