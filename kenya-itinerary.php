<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tribal Sand | From Safari to Sea</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <style>
        :root {
            --safari-orange: #d35400;
            --safari-blue: #5fa4cc;
            --text-dark: #2c2c2c;
            --text-muted: #777;
            --bg-cream: #f9f6f0;
        }

        body { font-family: 'Inter', sans-serif; color: var(--text-muted); line-height: 1.6; overflow-x: hidden; }
        .serif { font-family: 'Playfair Display', serif; }
        .ls-5 { letter-spacing: 5px; }

        /* --- REUSABLE --- */
        .btn-safari { padding: 14px 28px; border-radius: 0; font-weight: 600; font-size: 0.85rem; letter-spacing: 1px; transition: 0.4s; text-decoration: none; display: inline-block; }
        .btn-orange { background-color: var(--safari-orange); border: 1px solid var(--safari-orange); color: white; }
        .btn-outline-white { border: 1px solid white; color: white; }
        .btn-safari:hover { transform: translateY(-3px); color: white; opacity: 0.9; }
        .orange-divider { width: 50px; height: 2px; background-color: var(--safari-orange); margin: 25px auto; }

        /* --- SECTIONS --- */
        .hero-section {
            height: 100vh;
            background: linear-gradient(rgba(0,0,0,0.4), rgba(0,0,0,0.4)), url('images/masai-mara-sunset.jpg');
            background-size: cover; background-position: center;
            display: flex; align-items: center; justify-content: center; text-align: center; color: white;
        }
        .cream-section { background-color: var(--bg-cream); padding: 100px 0; text-align: center; }
        .white-section { background-color: #fff; padding: 100px 0; }
        
        .mara-section {
            padding: 140px 0;
            background: linear-gradient(rgba(0,0,0,0.7), rgba(0,0,0,0.7)), url('images/masai-mara-sunset.jpg');
            background-size: cover; background-attachment: fixed; color: white; text-align: center;
        }

        .beach-hero {
            padding: 160px 0;
            background: linear-gradient(rgba(0,0,0,0.4), rgba(0,0,0,0.4)), url('images/beach-watamu.jpg');
            background-size: cover; background-position: center; color: white; text-align: center;
        }

        .experience-card { background: white; padding: 50px; height: 100%; border: 1px solid #eee; transition: 0.3s; }
        .custom-list { list-style: none; padding: 0; }
        .custom-list li { position: relative; padding-left: 25px; margin-bottom: 10px; }
        .custom-list li::before { content: "•"; color: var(--safari-orange); position: absolute; left: 0; font-size: 1.5rem; top: -5px; }

        .final-cta {
            padding: 140px 0;
            background: linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.5)), url('https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&q=80&w=2000');
            background-size: cover; background-position: center; color: white; text-align: center;
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

<header class="hero-section">
    <div class="container" data-aos="fade-up">
        <p class="ls-5 mb-3">TRIBAL SAND</p>
        <h1 class="serif display-2">From Safari to Sea: The<br>Perfect 10 Day Kenya Journey</h1>
        <p class="serif fst-italic fs-4 mt-3">Experience lions at sunrise and the Indian Ocean at sunset.</p>
        <!-- <div class="d-flex justify-content-center gap-3 mt-5">
            <a href="#" class="btn-safari btn-orange">PLAN YOUR KENYA ESCAPE</a>
            <a href="#" class="btn-safari btn-outline-white">EXPLORE COASTAL STAYS</a>
        </div> -->
    </div>
</header>

<section class="white-section">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6" data-aos="fade-right">
                <h2 class="serif display-5 text-dark">Why Combine Safari and Beach in Kenya?</h2>
                <p>Few destinations in the world offer this level of contrast within a single journey. In Kenya, you can track lions at sunrise and swim in the Indian Ocean days later. You can witness the Great Migration and then dine barefoot under the stars.</p>
                <p class="fw-bold text-dark mt-4">This 10 day Kenya itinerary is designed for:</p>
                <ul class="custom-list mt-3">
                    <li>Honeymooners</li>
                    <li>Luxury couples</li>
                    <li>Adventure travelers</li>
                    <li>Safari and beach holiday planners</li>
                </ul>
                <p class="fst-italic mt-4">It blends wildlife intensity with coastal restoration.</p>
            </div>
            <div class="col-lg-6" data-aos="zoom-in">
                <img src="images/luxury-tent-camp.jpg" class="img-fluid shadow" alt="Safari Lodge">
            </div>
        </div>
    </div>
</section>

<section class="cream-section">
    <div class="container" data-aos="fade-up">
        <h2 class="serif display-5 text-dark">Who This Is Perfect For</h2>
        <div class="orange-divider"></div>
        <div class="row g-5 mt-4">
            <div class="col-md-4">
                <i class="bi bi-heart fs-1 text-orange mb-3"></i>
                <h3 class="serif h4 text-dark">Honeymooners</h3>
                <p class="small px-4">Safari romance followed by intimate beachfront evenings.</p>
            </div>
            <div class="col-md-4">
                <i class="bi bi-gem fs-1 text-orange mb-3"></i>
                <h3 class="serif h4 text-dark">Luxury Couples</h3>
                <p class="small px-4">High impact wildlife paired with refined hospitality.</p>
            </div>
            <div class="col-md-4">
                <i class="bi bi-compass fs-1 text-orange mb-3"></i>
                <h3 class="serif h4 text-dark">Active Travelers</h3>
                <p class="small px-4">Game drives, marine exploration, golf, and kitesurfing.</p>
            </div>
        </div>
    </div>
</section>

<section class="mara-section">
    <div class="container" data-aos="fade-up">
        <p class="text-warning ls-5 mb-2">DAYS 1 TO 3</p>
        <h2 class="serif display-3">Safari in the Masai Mara</h2>
        <div class="orange-divider"></div>
        <p class="serif fst-italic mb-5">What to Expect</p>
        <div class="row g-4 justify-content-center">
            <div class="col-6 col-md-2"><i class="bi bi-sun d-block mb-2 fs-2 text-warning"></i><p class="small">Game drives</p></div>
            <div class="col-6 col-md-2"><i class="bi bi-binoculars d-block mb-2 fs-2 text-warning"></i><p class="small">The Big Five</p></div>
            <div class="col-6 col-md-2"><i class="bi bi-wind d-block mb-2 fs-2 text-warning"></i><p class="small">Hot air balloon</p></div>
            <div class="col-6 col-md-2"><i class="bi bi-cup-straw d-block mb-2 fs-2 text-warning"></i><p class="small">Sundowners</p></div>
        </div>
        <div class="mt-5 p-4 d-inline-block border border-light opacity-75">
            <p class="serif m-0">"The Masai Mara delivers one of the most powerful wildlife experiences in Africa."</p>
        </div>
    </div>
</section>

<section class="cream-section">
    <div class="container" data-aos="fade-up">
        <i class="bi bi-airplane fs-1 text-orange mb-4"></i>
        <p class="text-orange ls-5 mb-2">DAY 4</p>
        <h2 class="serif display-4 text-dark">From the Mara to the Indian Ocean</h2>
        <p class="mx-auto mt-4" style="max-width: 600px;">A short domestic flight connects the Masai Mara to the Kenyan coast via Malindi or Mombasa. From there, private transfers bring you to Watamu or Kilifi.</p>
        <h3 class="serif text-dark fst-italic display-5 mt-5">The horizon turns blue. The pace slows.</h3>
    </div>
</section>

<section class="beach-hero">
    <div class="container" data-aos="fade-up">
        <p class="ls-5 mb-2">DAYS 5 TO 10</p>
        <h2 class="serif display-3">Where Safari Meets Serenity</h2>
        <div class="orange-divider"></div>
        <p class="mx-auto" style="max-width: 700px;">The coast offers coral reefs, dhow sunsets, kitesurf lagoons, and long quiet beaches. But choosing where to stay matters.</p>
    </div>
</section>

<section class="white-section">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-6" data-aos="fade-right">
                <div class="experience-card">
                    <h2 class="serif">Boutique Hotel Experience</h2>
                    <p class="small text-muted mb-4">Maya Kobe • Zuri</p>
                    <p class="fw-bold text-dark mt-4">Ideal For:</p>
                    <ul class="custom-list">
                        <li>Honeymooners / Couples</li>
                        <li>Travelers who want full service</li>
                    </ul>
                    <p class="fw-bold text-dark mt-4">What You Get:</p>
                    <ul class="custom-list">
                        <li>Individual suite bookings</li>
                        <li>In house chefs & Concierge</li>
                    </ul>
                    <p class="fst-italic border-start border-warning ps-3 mt-4">"You unpack once. Everything else is handled."</p>
                </div>
            </div>
            <div class="col-lg-6" data-aos="fade-left">
                <div class="experience-card">
                    <h2 class="serif">Private Villa Experience</h2>
                    <div class="orange-divider ms-0"></div>
                    <p class="fw-bold text-dark mt-4">Ideal For:</p>
                    <ul class="custom-list">
                        <li>Families / Groups / Celebrations</li>
                    </ul>
                    <p class="fw-bold text-dark mt-4">Features:</p>
                    <ul class="custom-list">
                        <li>Exclusive use property</li>
                        <li>Private pool and grounds</li>
                    </ul>
                    <p class="serif fst-italic text-dark fs-4 mt-5">Total privacy. Total control.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="cream-section">
    <div class="container" data-aos="fade-up">
        <h2 class="serif display-5 text-dark">What to Do on the Coast</h2>
        <div class="orange-divider"></div>
        <div class="row g-5 mt-4">
            <div class="col-md-4">
    <i class="bi bi-water fs-1 text-orange mb-3"></i>
    <h3 class="serif h4 text-dark">Marine Adventures</h3>
    <ul class="list-unstyled small text-muted lh-lg">
        <li>Snorkeling in Watamu Marine Park</li>
        <li>Deep sea fishing</li>
        <li>Paddle boarding and kayaking</li>
    </ul>   
</div>
            <div class="col-md-4">
                <i class="bi bi-tree fs-1 text-orange mb-3"></i>
                <h3 class="serif h4 text-dark">Cultural & Nature</h3>
                <ul class="list-unstyled small text-muted lh-lg">
                    <li>Dhow cruise on Kilifi Creek</li>
                    <li>Visit to Arabuko Sokoke Forest</li>
                    <li>Swahili cuisine exploration</li>
                </ul>
            </div>
            <div class="col-md-4">
                <i class="bi bi-stars fs-1 text-orange mb-3"></i>
                <h3 class="serif h4 text-dark">Pure Relaxation</h3>
                <ul class="list-unstyled small text-muted lh-lg">
                    <li>Beachfront spa treatments</li>
                    <li>Sunset cocktails</li>
                    <li>Long swims in the Indian Ocean</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<section class="white-section text-center">
    <div class="container" data-aos="fade-up">
        <h2 class="serif display-6 text-dark mb-5">Why This Itinerary Works</h2>
        <div class="serif display-4 text-dark lh-base">
            Wildlife. <br> Ocean. <br> Adventure. <br> Restoration. <br> Service.
        </div>
        <p class="text-muted mt-5 mx-auto" style="max-width: 500px;">It reflects Tribal Sand's philosophy of experiencing Kenya with authenticity, comfort, and sustainability.</p>
    </div>
</section>

<section class="final-cta">
    <div class="container" data-aos="zoom-in">
        <h2 class="serif display-3">Kenya As It Was Meant to Be Experienced</h2>
        <p class="mt-4 mb-5 opacity-75">Choose boutique refinement at Maya Kobe or Zuri. Or choose a private villa for exclusive use. Either way, the journey is seamless.</p>
        <div class="d-flex justify-content-center gap-3">
            <a href="#" class="btn-safari btn-orange">START PLANNING YOUR ESCAPE</a>
            <a href="#" class="btn-safari btn-outline-white">EXPLORE TRIBAL SAND STAYS</a>
        </div>
    </div>
</section>

<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>AOS.init({ duration: 1000, once: true });</script>
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