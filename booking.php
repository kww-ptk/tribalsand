<?php
// ── Guest booking lookup (when ?ref= is present) ──
if (!empty($_GET['ref'])) {
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/booking.php';
    $holdId = verify_guest_ref(trim($_GET['ref']));
    $hold = $holdId ? db_query(
        "SELECT h.*, r.name AS room_name FROM holds h
         JOIN units u ON u.id = h.unit_id JOIN rooms r ON r.id = u.room_id
         WHERE h.id = :id", [':id' => $holdId]
    )->fetch() : false;
    $page_title = 'Your Booking · Tribal Sand';
    $page_desc  = 'View your Tribal Sand booking.';
    $page_url   = 'https://tribalsand.com/booking';
    include __DIR__ . '/includes/head.php';
    echo '</head><body>';
    include __DIR__ . '/includes/header.php';
    echo '<main style="max-width:680px;margin:120px auto;padding:0 1.5rem;font-family:Jost,sans-serif">';
    if ($hold) {
        echo '<h1 style="font-family:\'Cormorant Garamond\',serif">Your booking</h1>';
        echo '<p>Reference: <strong>' . e($_GET['ref']) . '</strong></p>';
        echo '<p>Room: ' . e($hold['room_name']) . '</p>';
        echo '<p>Status: <strong>' . e(ucfirst($hold['status'])) . '</strong></p>';
        echo '<p>Check-in: ' . e($hold['check_in']) . ' · Check-out: ' . e($hold['check_out']) . '</p>';
    } else {
        echo '<h1 style="font-family:\'Cormorant Garamond\',serif">Booking not found</h1>';
        echo '<p>We couldn\'t find a booking for that reference. Please contact <a href="mailto:reservations@tribalsand.com">reservations@tribalsand.com</a>.</p>';
    }
    echo '</main>';
    include __DIR__ . '/includes/footer.php';
    echo '</body></html>';
    exit;
}
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

    <style>
        .hero-section {
            position: relative;
            min-height: 70vh;
            background: #2C3F58;
            opacity: 0.7;
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
            font-weight: bolder;
            text-transform: uppercase;
            font-style: normal;
            letter-spacing: 6px;
            text-shadow: 0px 0px 15px rgba(0, 0, 0, 0.8117647058823529);
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
        .booking-container .amenity-icon {
            color: #c6a96c;
            font-size: 1.2rem;
            margin-right: 10px;
        }

        .booking-container .amenity-info {
            color: #2C3F58;
            font-weight: 500;
            font-family: "Piazzolla", Sans-serif;
        }

        .booking-container .property-info i {
            color: #c6a96c;
            margin-bottom: 20px;
        }

        .booking-container .property-info {
            margin-bottom: 20px;
            color: #2C3F58;
            font-weight: 500;
            font-family: "Piazzolla", Sans-serif;
        }

        .booking-container .property-info i {
            margin-right: 5px;
        }

        .booking-container .booking-form {
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            border: 1px solid;
        }

        .booking-container .book-now-btn {
            background-color: #c6a96c;
            border: none;
            padding: 12px 30px;
            color: white;
            width: 100%;
            font-weight: 500;
        }

        .booking-container .book-now-btn:hover {
            background-color: #b39355;
        }

        .booking-container .section-title {
            color: #2C3F58;
            font-family: "Manrope", Sans-serif;
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 20px;
        }

        .booking-container .description {
            line-height: 1.6;
            margin-bottom: 30px;
            text-align: left;
            color: #2C3F58;
            font-weight: 500;
            font-family: "Piazzolla", Sans-serif;
        }
    </style>

    <style>
        /* Mobile-friendly styles */
        @media (max-width: 768px) {
            .booking-container .booking-form {
                padding: 20px;
            }

            .booking-container .form-control {
                font-size: 16px;
                /* Prevents zoom on mobile */
                padding: 10px;
            }

            .booking-container .book-now-btn {
                padding: 15px;
                font-size: 16px;
            }
        }

        /* Custom styles for date inputs */
        .booking-container input[type="date"] {
            position: relative;
            padding: 10px;
            background: #fff;
        }

        .booking-container input[type="date"]::-webkit-calendar-picker-indicator {
            position: absolute;
            right: 10px;
            cursor: pointer;
        }
    </style>

    <style>
        /* Add these styles to your existing CSS */
        .carousel {
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .carousel-item {
            height: 400px;
            /* Adjust height as needed */
        }

        .carousel-item img {
            object-fit: cover;
            width: 100%;
            height: 100%;
        }

        .carousel-control-prev,
        .carousel-control-next {
            width: 5%;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .carousel:hover .carousel-control-prev,
        .carousel:hover .carousel-control-next {
            opacity: 1;
        }

        .carousel-indicators {
            margin-bottom: 1rem;
        }

        .carousel-indicators button {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin: 0 5px;
        }

        /* Custom navigation arrows */
        .carousel-control-prev-icon,
        .carousel-control-next-icon {
            background-color: rgba(0, 0, 0, 0.5);
            border-radius: 50%;
            padding: 20px;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .carousel-item {
                height: 300px;
            }

            .carousel-control-prev,
            .carousel-control-next {
                width: 10%;
                opacity: 1;
            }
        }
    </style>


</head>


<body>
    <?php include 'includes/g-tag-body.php'; ?>

    <?php include 'includes/header.php'; ?>

    <section class="hero-section">
        <div class="container">
            <h1 class="hero-title">Booking</h1>
        </div>
    </section>

    <div class="booking-container container my-5">
        <div class="row">
            <!-- Property Details Column -->
            <div class="col-lg-8 border-right">
                <h1 class="section-title">My Amani</h1>
                <p class="description">
                    Situated on the pristine Vipingo Beach, along the North Coast of Kenya, overlooking the Indian ocean
                    lies Vipingo’s best kept secret – My Amani. A stunning, tastefully furnished 5-bedroom house offers
                    you a serene yet luxurious stay with endless views of the Indian Ocean at one end, and lush,
                    well-manicured gardens on the other. In between lies a state of the art, spacious yet intimate
                    hideaway that you get to call home for your stay. A seductive haven; My Amani truly lives up to her
                    name – Peaceful; giving you every reason to keep coming back.
                </p>

                <!-- Property Info -->
                <div class="row property-info">
                    <div class="col-md-4">
                        <i class="fas fa-bed"></i> 5 Bed rooms
                    </div>
                    <div class="col-md-4">
                        <i class="fas fa-bath"></i> 5 Bath room
                    </div>
                    <div class="col-md-4">
                        <i class="fas fa-ruler-combined"></i> 1500 sq.
                    </div>
                </div>

                <!-- Amenities Section -->
                <h3 class="section-title" style="font-size: 1.5rem;">Amenities</h3>
                <div class="row amenity-info mb-4">
                    <div class="col-md-4 mb-3">
                        <i class="fas fa-parking amenity-icon"></i> Free parking
                    </div>
                    <div class="col-md-4 mb-3">
                        <i class="fas fa-wifi amenity-icon"></i> Fast Wi-Fi
                    </div>
                    <div class="col-md-4 mb-3">
                        <i class="fas fa-cocktail amenity-icon"></i> Kitchen
                    </div>
                    <div class="col-md-4 mb-3">
                        <i class="fas fa-swimming-pool amenity-icon"></i> Swimming Pool
                    </div>
                    <div class="col-md-4 mb-3">
                        <i class="fas fa-coffee amenity-icon"></i> Coffee
                    </div>
                    <div class="col-md-4 mb-3">
                        <i class="fas fa-snowflake amenity-icon"></i> Air Conditioning
                    </div>
                </div>

                <!-- Image Slider -->
                <div id="propertySlider" class="carousel slide mb-4" data-bs-ride="carousel">
                    <!-- Carousel Indicators -->
                    <div class="carousel-indicators">
                        <button type="button" data-bs-target="#propertySlider" data-bs-slide-to="0" class="active"
                            aria-current="true" aria-label="Slide 1"></button>
                        <button type="button" data-bs-target="#propertySlider" data-bs-slide-to="1"
                            aria-label="Slide 2"></button>
                        <button type="button" data-bs-target="#propertySlider" data-bs-slide-to="2"
                            aria-label="Slide 3"></button>
                    </div>

                    <!-- Carousel Items -->
                    <div class="carousel-inner">
                        <div class="carousel-item active">
                            <img src="images/tribal-3.jpg.webp" class="d-block w-100" alt="Pool at night">
                        </div>
                        <div class="carousel-item">
                            <img src="images/tribal-3.jpg.webp" class="d-block w-100" alt="Property view">
                        </div>
                        <div class="carousel-item">
                            <img src="images/tribal-3.jpg.webp" class="d-block w-100" alt="Interior view">
                        </div>
                    </div>

                    <!-- Navigation Arrows -->
                    <button class="carousel-control-prev" type="button" data-bs-target="#propertySlider"
                        data-bs-slide="prev">
                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Previous</span>
                    </button>
                    <button class="carousel-control-next" type="button" data-bs-target="#propertySlider"
                        data-bs-slide="next">
                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Next</span>
                    </button>
                </div>
            </div>



            <!-- Booking Form Column -->
            <div class="col-lg-4">
                <div class="booking-form">
                    <h2 class="section-title text-center border-bottom">Book Your Room</h2>
                    <form id="bookingForm">
                        <div class="mb-3">
                            <input type="text" class="form-control" placeholder="Name">
                        </div>
                        <div class="mb-3">
                            <input type="email" class="form-control" placeholder="Email">
                        </div>
                        <div class="mb-3">
                            <label for="checkIn" class="form-label">Check In</label>
                            <input type="date" class="form-control" id="checkIn" required>
                        </div>
                        <div class="mb-3">
                            <label for="checkOut" class="form-label">Check Out</label>
                            <input type="date" class="form-control" id="checkOut" required>
                        </div>
                        <div class="mb-3">
                            <input type="number" class="form-control" placeholder="Number of Adults">
                        </div>
                        <div class="mb-3">
                            <input type="number" class="form-control" placeholder="Number of Kids">
                        </div>
                        <button type="submit" class="book-now-btn">Book Now</button>
                    </form>
                </div>

            </div>

        </div>
    </div>





    <?php include 'includes/footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Get form elements
        const checkInInput = document.getElementById('checkIn');
        const checkOutInput = document.getElementById('checkOut');
        const bookingForm = document.getElementById('bookingForm');
        const errorDisplay = document.getElementById('dateError');

        // Set minimum and maximum dates
        const today = new Date();
        const maxDate = new Date();
        maxDate.setFullYear(today.getFullYear() + 1); // Allow booking up to 1 year in advance

        // Format date to YYYY-MM-DD
        function formatDate(date) {
            const d = new Date(date);
            const month = (d.getMonth() + 1).toString().padStart(2, '0');
            const day = d.getDate().toString().padStart(2, '0');
            return `${d.getFullYear()}-${month}-${day}`;
        }

        // Initialize date inputs
        function initializeDateInputs() {
            // Set min/max attributes for check-in
            checkInInput.min = formatDate(today);
            checkInInput.max = formatDate(maxDate);

            // Set initial min for check-out to today + 1
            const tomorrow = new Date(today);
            tomorrow.setDate(tomorrow.getDate() + 1);
            checkOutInput.min = formatDate(tomorrow);
            checkOutInput.max = formatDate(maxDate);

            // Add event listeners
            checkInInput.addEventListener('change', updateCheckOutMin);
            // checkOutInput.addEventListener('change', validateDates);

            // Set default values (optional)
            checkInInput.value = formatDate(today);
            checkOutInput.value = formatDate(tomorrow);
        }

        // Update check-out minimum date based on check-in selection
        function updateCheckOutMin() {
            const checkInDate = new Date(checkInInput.value);
            const minCheckOutDate = new Date(checkInDate);
            minCheckOutDate.setDate(checkInDate.getDate() + 1);

            // Update check-out minimum date
            checkOutInput.min = formatDate(minCheckOutDate);

            // If check-out date is now invalid, update it
            if (new Date(checkOutInput.value) <= checkInDate) {
                checkOutInput.value = formatDate(minCheckOutDate);
            }

        }





        // Initialize everything when the DOM is loaded
        document.addEventListener('DOMContentLoaded', () => {
            initializeDateInputs();
        });

        // Mobile-friendly date input fallback
        function enableMobileDateInput() {
            if (/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)) {
                checkInInput.type = 'date';
                checkOutInput.type = 'date';
            }
        }

        enableMobileDateInput();
    </script>

    <script>
        // Add this JavaScript to initialize the carousel and add some features
        document.addEventListener('DOMContentLoaded', function () {
            // Initialize Bootstrap Carousel
            const carousel = new bootstrap.Carousel(document.getElementById('propertySlider'), {
                interval: 5000, // Time between automatic slides (5 seconds)
                wrap: true,     // Continuous loop
                touch: true     // Enable touch swipe on mobile
            });

            // Optional: Pause on hover
            document.getElementById('propertySlider').addEventListener('mouseenter', function () {
                carousel.pause();
            });

            document.getElementById('propertySlider').addEventListener('mouseleave', function () {
                carousel.cycle();
            });

            // Optional: Enable keyboard navigation
            document.addEventListener('keydown', function (event) {
                if (event.key === 'ArrowLeft') {
                    carousel.prev();
                }
                if (event.key === 'ArrowRight') {
                    carousel.next();
                }
            });
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