 <!-- header section  -->

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>A La Carte Dining in Watamu & Kilifi | TribalSand Boutique Hotels</title>
    <link rel="icon" href="images/favicon.png">
    <link rel="canonical" href="https://tribalsand.com/a-la-carte-dining.php" />
    <meta name="description" 
      content="Experience refined à la carte dining at Tribal Sand boutique hotels in Watamu and Kilifi. Enjoy fresh seafood, Swahili-inspired flavors, and intimate beachfront service designed for couples and honeymooners on the Kenya coast.">
    <meta name="keywords" 
      content="À la carte dining Watamu, Zuri Watamu, Maya Kobe, luxury retreat Kenya, beachfront dining Kilifi, TribalSand retreats, romantic dining Kenya, Swahili cuisine Kenya">
    <meta property="og:title" 
      content="À La Carte Dining in Watamu & Kilifi | TribalSand Boutique Hotels">
    <meta property="og:description" 
      content="Enjoy refined à la carte dining at Tribal Sand boutique hotels in Watamu and Kilifi. Fresh seafood, Swahili flavors, and intimate beachfront service for couples and honeymooners.">
    <meta property="og:url" 
      content="https://tribalsand.com/a-la-carte-dining">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="TribalSand">
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>



</head>
<style>
     p {
        font-family: "Piazzolla", Sans-serif;
    }
    /* --- SHARED TYPOGRAPHY & UTILITIES --- */
.serif-font, .italic-font, .quote-block h3 { font-family: 'Piazzolla', Sans-serif; }
.italic-font { font-style: italic; font-weight: 400; }
.tracking-widest { letter-spacing: 0.25rem; font-size: 0.85rem; font-weight: 600; text-transform: uppercase; }
.text-muted { color: #6c757d !important; line-height: 1.7; }
.rounded-4 { border-radius: 1.5rem !important; }

/* --- HERO SECTIONS --- */
.dining-hero, .transition-hero { 
    background-size: cover; 
    position: relative; 
    overflow: hidden; 
}
.dining-hero { height: 100vh; min-height: 600px; background-attachment: fixed; background-position: center; }
.transition-hero { height: 80vh; min-height: 500px; background-position: center bottom; }

.white-gradient-overlay {
    position: absolute; inset: 0;
    background: linear-gradient(to bottom, rgba(255,255,255,0) 60%, #fff 100%);
    z-index: 1;
}

/* --- COMPONENTS (Buttons & Cards) --- */
.btn-outline-light, .btn-light { 
    padding: 1rem 3rem; border-radius: 50px; text-transform: uppercase; 
    font-size: 0.8rem; letter-spacing: 1px; transition: 0.3s; 
}
.btn-outline-light { border: 2px solid #fff; color: #fff; }
.btn-outline-light:hover { background: #fff; color: #000; }
.btn-light { background: #fff; color: #0b1d26; border: none; font-weight: 600; }
.btn-light:hover { background: #f8f7f5; transform: translateY(-2px); }

.feature-card, .choice-card { border-radius: 12px; transition: 0.3s; }
.feature-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(0,0,0,0.05)!important; }

/* --- SECTIONS --- */
.arch-container { max-width: 500px; margin: 0 auto; }
.arched-image { 
    width: 100%; height: 600px; object-fit: cover; 
    border-radius: 250px 250px 0 0; box-shadow: 0 15px 35px rgba(0,0,0,0.1); 
}

.gallery-item { position: relative; overflow: hidden; height: 400px; }
.gallery-item img { width: 100%; height: 100%; object-fit: cover; transition: 0.5s; }
.gallery-item:hover img { transform: scale(1.05); }
.gallery-label { 
    position: absolute; bottom: 20px; left: 20px; color: #fff; 
    font-size: 0.75rem; font-weight: 600; letter-spacing: 1.5px; text-shadow: 1px 1px 4px rgba(0,0,0,0.5); 
}

.heritage-image-wrapper img, .romance-image-wrapper img { width: 100%; height: 500px; object-fit: cover; }

.icon-circle { 
    width: 45px; height: 45px; background: #fff; border-radius: 50%; 
    display: flex; align-items: center; justify-content: center; 
    box-shadow: 0 4px 10px rgba(0,0,0,0.03); color: #6c757d; 
}

.choice-card { min-height: 450px; padding: 3rem; }
.dark-card { background: #2c3e50; color: #fff; }
.light-card { background: #fafafa; border: 1px solid #eee; }
.custom-list li { position: relative; padding-left: 20px; margin-bottom: 12px; list-style: none; font-size: 0.95rem; }
.custom-list li::before { content: "•"; position: absolute; left: 0; opacity: 0.5; }

/* --- RESPONSIVE --- */
@media (max-width: 991px) {
    .arched-image { height: 450px; border-radius: 200px 200px 0 0; }
    .heritage-image-wrapper img, .romance-image-wrapper img { height: 350px; }
    .choice-card { min-height: auto; margin-bottom: 20px; }
}

@media (max-width: 768px) {
    .dining-hero h1 { font-size: 2.5rem; }
    .gallery-item { height: 300px; }
    .transition-hero { height: 60vh; }
    .closing-thought h2 { font-size: 1.75rem; }
}
</style>
<!-- // body section  -->
<body>
    

    <?php include "includes/header.php"; ?>

    <section class="dining-hero" style="background-image: linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url('images/blog/hero-dining.jpg');">
    <div class="container h-100 d-flex align-items-center justify-content-center">
        <div class="text-center text-white">
            <h6 class="text-uppercase tracking-widest mb-3">Watamu & Kilifi, Kenya Coast</h6>
            <h1 class="display-3 serif-font mb-4">A La Carte Dining at Our<br>Boutique Hotels</h1>
            <p class="lead mb-5 italic-font">Where the Indian Ocean meets the Swahili soul.</p>
        </div>
    </div>
</section>

<section class="experience-section py-5">
    <div class="container py-5">
        <div class="row align-items-center">
            <div class="col-lg-6 mb-5 mb-lg-0">
                <div class="pe-lg-5">
                    <h6 class="text-uppercase tracking-widest text-muted mb-3" style="font-size: 0.75rem;">The Experience</h6>
                    <h2 class="serif-font display-5 mb-4" style="color: #0b1d26;">A Different Kind of Coastal Dining Experience</h2>
                    
                    <p class="text-muted mb-4 lead-sm">
                        There are no buffets here. No crowds, no trays, no lines. At TribalSand, every meal is prepared for you — not alongside twenty other tables, but for yours alone.
                    </p>
                    
                    <p class="text-muted">
                        Dining here is not an event; it is a conversation with the coast. The catch of the day isn’t a menu item — it’s a morning ritual. Our fisherman arrive at dawn, and by dusk, your table is set.
                    </p>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="arch-container">
                    <img src="images/blog/intimate-dining.jpg" alt="Romantic Beach Dining" class="img-fluid arched-image">
                </div>
            </div>
        </div>
    </div>
</section>

<section class="freedom-section py-5" style="background-color: #f9f7f4;">
    <div class="container py-5">
        <div class="row mb-5">
            <div class="col-lg-8">
                <h6 class="text-uppercase tracking-widest text-muted mb-3" style="font-size: 0.75rem;">Unhurried</h6>
                <h2 class="serif-font display-4" style="color: #0b1d26;">The Freedom of Not Having to Think About Meals</h2>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="feature-card h-100 p-5 bg-white border-0 shadow-sm">
                    <div class="icon-box mb-4">
                        <i class="bi bi-sun text-muted fs-3"></i>
                    </div>
                    <h4 class="serif-font mb-3">Morning Ease</h4>
                    <p class="text-muted small-text">Wake to the sound of waves. Breakfast is ready when you are — no alarms, no rush, no schedule.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="feature-card h-100 p-5 bg-white border-0 shadow-sm">
                    <div class="icon-box mb-4">
                        <i class="bi bi-fork-knife text-muted fs-3"></i>
                    </div>
                    <h4 class="serif-font mb-3">Curated Menus</h4>
                    <p class="text-muted small-text">Each day's menu reflects the morning catch and the season's freshest ingredients. Always different, always considered.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="feature-card h-100 p-5 bg-white border-0 shadow-sm">
                    <div class="icon-box mb-4">
                        <i class="bi bi-waves text-muted fs-3"></i>
                    </div>
                    <h4 class="serif-font mb-3">Effortless Flow</h4>
                    <p class="text-muted small-text">From the poolside to the dining table, every meal arrives without you ever having to think about it.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="harvest-section py-5 bg-white">
    <div class="container py-5">
        <div class="row mb-5">
            <div class="col-lg-6">
                <h6 class="text-uppercase tracking-widest text-muted mb-3" style="font-size: 0.75rem;">The Harvest</h6>
                <h2 class="serif-font display-5 mb-4" style="color: #0b1d26;">Fresh Seafood from Local Fishermen</h2>
                <p class="text-muted" style="max-width: 500px;">
                    The catch of the day isn’t a menu item; it’s a morning ritual. Our fishermen arrive at dawn, and by dusk, your table is set with the freshest the Indian Ocean has to offer.
                </p>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="gallery-item">
                    <img src="images/blog/seafood-lobster.jpg" alt="Grilled Lobster" class="img-fluid rounded-4">
                    <div class="gallery-label text-uppercase">Grilled Lobster</div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="gallery-item">
                    <img src="images/blog/seafood-prawns.jpg" alt="Jumbo Prawns" class="img-fluid rounded-4">
                    <div class="gallery-label text-uppercase">Jumbo Prawns</div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="gallery-item">
                    <img src="images/blog/seafood-fish.jpg" alt="Catch of the Day" class="img-fluid rounded-4">
                    <div class="gallery-label text-uppercase">Catch of the Day</div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="heritage-section py-5" style="background-color: #f8f7f5;">
    <div class="container py-5">
        <div class="row align-items-center">
            <div class="col-lg-6 mb-5 mb-lg-0">
                <div class="heritage-image-wrapper pe-lg-4">
                    <img src="images/blog/swahili-spices.jpg" alt="Swahili Spices and Coconut" class="img-fluid rounded-4 shadow-sm">
                </div>
            </div>

            <div class="col-lg-6">
                <div class="ps-lg-5">
                    <h6 class="text-uppercase tracking-widest text-muted mb-3" style="font-size: 0.75rem;">Heritage</h6>
                    <h2 class="serif-font display-5 mb-4" style="color: #0b1d26;">Swahili Influence with Refined Execution</h2>
                    
                    <p class="text-muted mb-4" style="line-height: 1.8;">
                        Our kitchens draw from centuries of coastal trade — coconut milk, tamarind, cardamom, and fresh-ground spice blends that carry the memory of the Swahili coast.
                    </p>
                    
                    <p class="text-muted" style="line-height: 1.8;">
                        Every dish is a quiet act of storytelling. The flavours are familiar to this coastline, but the presentation is refined — a balance between heritage and elegance that defines the TribalSand table.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
<section class="romance-section py-5 bg-white">
    <div class="container py-5">
        <div class="row align-items-center">
            <div class="col-lg-6 mb-5 mb-lg-0">
                <div class="pe-lg-5">
                    <h6 class="text-uppercase tracking-widest text-muted mb-3" style="font-size: 0.75rem;">Romance</h6>
                    <h2 class="serif-font display-5 mb-4" style="color: #0b1d26;">Designed for Couples and Honeymooners</h2>
                    
                    <p class="text-muted mb-4" style="line-height: 1.8;">
                        At Maya Kobe and Zuri, candlelight is not a decoration — it is the architecture of the evening. Tables are arranged to feel private, intimate, and entirely yours.
                    </p>
                    
                    <p class="text-muted" style="line-height: 1.8;">
                        Whether you dine barefoot on the sand or beneath the open sky of a coral-stone courtyard, every detail is shaped around the two of you.
                    </p>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="romance-image-wrapper">
                    <img src="images/blog/romance-dining.jpg" alt="Romantic Beach Dinner at Sunset" class="img-fluid rounded-4 shadow-sm">
                </div>
            </div>
        </div>
    </div>
</section>
<section class="service-section py-5" style="background-color: #f9f7f4;">
    <div class="container py-5">
        <div class="row">
            <div class="col-lg-8 offset-lg-2">
                <h6 class="text-uppercase tracking-widest text-muted mb-3" style="font-size: 0.75rem;">Attentive</h6>
                <h2 class="serif-font display-4 mb-5" style="color: #0b1d26;">Service That Feels Effortless</h2>

                <div class="service-list">
                    <div class="d-flex align-items-center mb-4">
                        <div class="icon-circle me-4">
                            <i class="bi bi-heart"></i>
                        </div>
                        <p class="mb-0 text-muted">Personalized menus tailored to your dietary preferences</p>
                    </div>

                    <div class="d-flex align-items-center mb-4">
                        <div class="icon-circle me-4">
                            <i class="bi bi-clock"></i>
                        </div>
                        <p class="mb-0 text-muted">Meals served at your pace — no fixed dining hours</p>
                    </div>

                    <div class="d-flex align-items-center mb-4">
                        <div class="icon-circle me-4">
                            <i class="bi bi-stars"></i>
                        </div>
                        <p class="mb-0 text-muted">Special occasion setups arranged without asking</p>
                    </div>

                    <div class="d-flex align-items-center mb-4">
                        <div class="icon-circle me-4">
                            <i class="bi bi-person-check"></i>
                        </div>
                        <p class="mb-0 text-muted">A dedicated host who remembers your preferences</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="choice-section py-5 bg-white">
    <div class="container py-5 text-center">
        <h6 class="text-uppercase tracking-widest text-muted mb-3" style="font-size: 0.75rem;">The Choice</h6>
        <h2 class="serif-font display-5 mb-5" style="color: #0b1d26;">Boutique Hotel Dining vs Private Villa Catering</h2>

        <div class="row g-0 align-items-center justify-content-center mt-4">
            <div class="col-lg-4">
                <div class="choice-card dark-card p-5 text-start shadow">
                    <h6 class="text-uppercase tracking-widest mb-4" style="font-size: 0.7rem; opacity: 0.7;">Boutique Hotels</h6>
                    <h3 class="serif-font mb-4">Seamless Service</h3>
                    <ul class="list-unstyled custom-list">
                        <li>Fully managed à la carte dining</li>
                        <li>Daily-changing menus by our chefs</li>
                        <li>Beachside and courtyard settings</li>
                        <li>Breakfast, lunch, and dinner included</li>
                        <li>Dedicated hospitality team</li>
                    </ul>
                </div>
            </div>

            <div class="col-lg-1 d-none d-lg-block">
                <span class="vs-text italic-font">vs</span>
            </div>

            <div class="col-lg-4">
                <div class="choice-card light-card p-5 text-start shadow-sm border">
                    <h6 class="text-uppercase tracking-widest mb-4" style="font-size: 0.7rem; color: #b08d57;">Private Villas</h6>
                    <h3 class="serif-font mb-4">Total Seclusion</h3>
                    <ul class="list-unstyled custom-list text-muted">
                        <li>Private chef available on request</li>
                        <li>Flexible meal times and menus</li>
                        <li>Exclusive use of kitchen and dining areas</li>
                        <li>Grocery provisioning and wine selection</li>
                        <li>Complete privacy for your group</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>
<section class="transition-hero" style="background-image: url('images/blog/closing-lifestyle.jpg');">
    <div class="white-gradient-overlay"></div>
    <div class="container h-100 position-relative" style="z-index: 2;">
        <div class="row h-100 align-items-center justify-content-center">
            <div class="col-md-8 text-center">
                <h6 class="text-uppercase tracking-widest text-white mb-4" style="font-size: 0.8rem;">Beyond the Table</h6>
                <h2 class="serif-font display-4 text-white mb-5">Waking up to the Indian Ocean</h2>
                
            </div>
        </div>
    </div>
</section>
<section class="closing-thought py-5 bg-white">
    <div class="container py-5 text-center">
        <div class="row justify-content-center">
            <div class="col-lg-7">
                <h6 class="text-uppercase tracking-widest text-muted mb-3" style="font-size: 0.75rem;">A Closing Thought</h6>
                <h2 class="serif-font display-5 mb-4" style="color: #0b1d26;">A Culinary Experience Without Effort</h2>
                <p class="text-muted mb-5" style="line-height: 1.8;">
                    At TribalSand, the table is never a transaction. It is part of the landscape — as natural as the tide, as unhurried as the afternoon light. You won't remember what you ordered. You'll remember how it felt.
                </p>
                
                <hr class="my-5 mx-auto" style="width: 50px; border-top: 1px solid #ddd;">

                <div class="quote-block mt-5">
                    <h3 class="serif-font italic-font mb-2" style="font-size: 2rem; color: #0b1d26;">"Simplicity is the ultimate sophistication."</h3>
                    <p class="tracking-widest text-muted mt-3" style="font-size: 0.7rem;">3°22'S 39°59'E — KENYA COAST</p>
                </div>
            </div>
        </div>
    </div>
</section>


    
    <?php include "includes/footer.php"; ?>




<!-- // script section  -->

   
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>

    <!-- IntlTelInput Script -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/intlTelInput.min.js"></script>
    <script>
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


    <!-- video display -->
    <script>
    function playVideo(overlay) {
        const videoFrame = document.getElementById('video-frame');
        const iframe = document.createElement('iframe');
        iframe.setAttribute('src', 'https://www.youtube.com/embed/Iz9-oNaQkzo?autoplay=1');
        iframe.style.position = 'absolute';
        iframe.style.top = '0';
        iframe.style.left = '0';
        iframe.style.width = '100%';
        iframe.style.height = '100%';
        iframe.style.border = 'none';

        videoFrame.appendChild(iframe);
        overlay.style.display = 'none';
    }
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

    <script src="https://code.jquery.com/ui/1.11.4/jquery-ui.js" type="text/javascript" language="javascript"></script>
    <script>
    jQuery('#select_hotel option:eq(0)').attr('selected', 'selected');
    jQuery(document).ready(function() {
        jQuery('#select_hotel option:eq(0)').attr('selected', 'selected');
        jQuery("input[id$='eZ_chkin'],input[id$='eZ_chkout']").datepicker({
            changeMonth: true,
            numberOfMonths: 1,
            dateFormat: "dd-mm-yy",
            minDate: "0", //default day from today
            maxDate: '',
            onSelect: function(selectedDate) {
                console.log(jQuery("input[id$='eZ_chkin']").datepicker('getDate'));
                if (jQuery("input[id$='eZ_chkin']").attr("id") == jQuery(this).attr("id")) {
                    var date2 = jQuery("input[id$='eZ_chkin']").datepicker('getDate');
                    date2.setDate(date2.getDate() + 1);
                    jQuery("input[id$='eZ_chkout']").datepicker('setDate', date2);
                    jQuery("input[id$='eZ_chkout']").datepicker('option', 'minDate', date2);
                }

            }
        });
        jQuery("input[id$='eZ_chkin']").datepicker('setDate', new Date());
        var date2 = jQuery("input[id$='eZ_chkin']").datepicker('getDate');
        date2.setDate(date2.getDate() + 1);
        jQuery("input[id$='eZ_chkout']").datepicker('setDate', date2);
        jQuery("input[id$='eZ_chkout']").datepicker('option', 'minDate', date2);
    });

    function _setNights(cutoffdays) {
        jQuery("select[id$='eZ_Nights']").val(parseInt(cutoffdays));
    }

    function BB_valiDateFomData() {


        if (jQuery("#select_hotel").val() == '0') {
            alert("Please Select your Preferred Hotel");
            jQuery('#select_hotel').focus();
            return false;
        }

        if (jQuery("#eZ_chkin").val() == '') {
            alert("Please select checkin date");
            jQuery('#eZ_chkin').focus();
            return false;
        }
        document.forms['_resBBBox'].action = 'https://live.ipms247.com/booking/book-rooms-' + jQuery("#select_hotel")
            .val();
        document.forms['_resBBBox'].submit();
        return true;
    }
    </script>

    </script>

</body>
</html>