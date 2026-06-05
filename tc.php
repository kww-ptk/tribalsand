<?php require_once 'includes/schema.php'; ?>
<?php
$page_title  = 'Terms & Conditions · Tribal Sand Kenya';
$page_desc   = 'Read Tribal Sand\'s terms and conditions for bookings, reservations, payments, cancellations and use of our website.';
$page_url    = 'https://tribalsand.com/tc.php';
$page_schema = ts_schema_org() . ts_schema_breadcrumb([
    ['name' => 'Home',              'url' => 'https://tribalsand.com/'],
    ['name' => 'Terms & Conditions','url' => 'https://tribalsand.com/tc.php'],
]);
require_once 'includes/head.php';
?>
<body>
<?php include 'includes/header.php'; ?>

<!-- LEGAL CONTENT -->
<style>
.legal-hero{background:var(--teal-d,#102F3A);padding:5rem var(--px,5vw) 2.5rem;color:#fff;}
.legal-eyebrow{font-size:.52rem;letter-spacing:.38em;text-transform:uppercase;color:rgba(184,150,90,.6);margin-bottom:.75rem;}
.legal-h1{font-family:'Cormorant Garamond',serif;font-size:clamp(2rem,5vw,3.2rem);font-weight:300;color:#fff;margin:0;}
.legal-wrap{max-width:900px;margin:0 auto;padding:3rem var(--px,5vw);}
.legal-wrap h2{font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:400;color:var(--teal,#1E5C6B);margin:2rem 0 .75rem;padding-top:1rem;border-top:1px solid var(--border,rgba(184,150,90,.15));}
.legal-wrap h2:first-child{border-top:none;margin-top:0;}
.legal-wrap h3{font-size:1rem;font-weight:500;color:var(--dark,#141412);margin:1.5rem 0 .5rem;}
.legal-wrap p{font-size:1rem;color:var(--mid,#6B6050);line-height:1.8;margin-bottom:1rem;}
.legal-wrap ul{margin:0 0 1rem 1.5rem;padding:0;}
.legal-wrap li{font-size:1rem;color:var(--mid,#6B6050);line-height:1.8;margin-bottom:.25rem;}
.legal-wrap hr{border:none;border-top:1px solid var(--border,rgba(184,150,90,.15));margin:1.5rem 0;}
.legal-wrap a{color:var(--teal,#1E5C6B);}
.legal-wrap strong{color:var(--dark,#141412);}
.legal-updated{font-size:.72rem;letter-spacing:.1em;color:rgba(184,150,90,.5);margin-top:3rem;padding-top:1.5rem;border-top:1px solid var(--border,rgba(184,150,90,.15));}
</style>

<section class="legal-hero">
  <p class="legal-eyebrow">Legal</p>
  <h1 class="legal-h1">Terms &amp; Conditions</h1>
</section>

<div class="legal-wrap">

  <p>Welcome to <strong>Tribal Sand</strong>. By accessing and using our website <a href="https://tribalsand.com/">https://tribalsand.com/</a>, you agree to comply with and be bound by the following Terms and Conditions. Please read them carefully.</p>

  <h2>1. Use of the Website</h2>
  <p>This website is provided for general information, restaurant details, reservations, and customer inquiries. You agree to use this website only for lawful purposes and in a way that does not infringe the rights of others or restrict their use of the site.</p>

  <h2>2. Reservations &amp; Dining</h2>
  <p>All reservations made through our website are subject to availability and confirmation. Tribal Sand reserves the right to cancel or modify reservations due to unforeseen circumstances, including weather conditions, private events, or operational requirements.</p>
  <p>We request guests to arrive on time. Late arrivals may result in reduced dining time or cancellation of the reservation.</p>

  <h2>3. Payments</h2>
  <p>If online payments or deposits are accepted, they are processed securely through third-party payment providers. Tribal Sand does not store your credit/debit card details.</p>
  <p>Prices, menus, and availability are subject to change without prior notice.</p>

  <h2>4. Cancellations &amp; Refunds</h2>
  <p>Cancellation and refund policies may vary based on events, group bookings, or special promotions. Please refer to specific booking terms provided at the time of reservation or contact us directly.</p>

  <h2>5. Intellectual Property</h2>
  <p>All content on this website, including text, images, logos, and design, is the property of Tribal Sand unless otherwise stated. You may not reproduce, distribute, or use any content without our prior written permission.</p>

  <h2>6. User Submissions</h2>
  <p>Any information you submit through the website (inquiries, reviews, feedback, or forms) must be accurate and not unlawful, offensive, or harmful. We reserve the right to remove inappropriate content.</p>

  <h2>7. Limitation of Liability</h2>
  <p>Tribal Sand shall not be liable for any direct, indirect, incidental, or consequential damages resulting from the use or inability to use this website, including reliance on any information provided.</p>

  <h2>8. Third-Party Links</h2>
  <p>This website may include links to third-party websites. We are not responsible for their content, services, or privacy practices.</p>

  <h2>9. Privacy</h2>
  <p>Your use of this website is also governed by our <a href="privacy_policy.php">Privacy Policy</a>. Please review it to understand how we collect and use information.</p>

  <h2>10. Changes to These Terms</h2>
  <p>We reserve the right to update or modify these Terms &amp; Conditions at any time. Changes will be effective immediately upon posting on this page.</p>

  <h2>11. Governing Law</h2>
  <p>These Terms &amp; Conditions are governed by the laws of Kenya. Any disputes arising will be subject to the jurisdiction of Kenyan courts.</p>

  <h2>12. Contact Us</h2>
  <p><strong>Tribal Sand</strong><br>
  Website: <a href="https://tribalsand.com/">https://tribalsand.com/</a><br>
  Email: <a href="mailto:reservations@tribalsand.com">reservations@tribalsand.com</a></p>

  <p class="legal-updated">Last reviewed: April 2026</p>

</div>
<!-- /LEGAL CONTENT -->

<?php include 'includes/footer.php'; ?>
</body>
