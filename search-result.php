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

            min-height: 60vh;

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

            background-image: url('images/updated-hero-banner_auto_x2.jpg');

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

                    <h1 class="main-title">Search Result</h1>

                    

                </div>

            </div>

        </div>

    </section>





    <section class="search-results">

        <div class="container-fluid py-4">

            <!-- OwnerRez Availability/Property Search widget -->

            <div class="ownerrez-widget" data-widget-type="Availability/Property Search" data-widgetId="584c355942b947dfb8096287bced69be"></div>

            <script src="https://app.ownerrez.com/widget.js"></script>

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