<?php require_once 'includes/schema.php'; ?>
<?php
$page_title  = 'Privacy Policy · Tribal Sand Kenya';
$page_desc   = 'Read Tribal Sand\'s privacy policy — how we collect, use and protect your personal information.';
$page_url    = 'https://tribalsand.com/privacy_policy.php';
$page_schema = ts_schema_org() . ts_schema_breadcrumb([
    ['name' => 'Home',           'url' => 'https://tribalsand.com/'],
    ['name' => 'Privacy Policy', 'url' => 'https://tribalsand.com/privacy_policy.php'],
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
  <h1 class="legal-h1">Privacy Policy</h1>
</section>

<div class="legal-wrap">

  <p>Welcome to <strong>Tribal Sand</strong>. We respect your privacy and are committed to protecting your personal information. This Privacy Policy explains how we collect, use, and safeguard your information when you visit <a href="https://tribalsand.com/">https://tribalsand.com/</a>.</p>

  <h2>1. Information We Collect</h2>

  <h3>a) Personal Information</h3>
  <p>When you make a reservation, fill out a contact form, subscribe to updates, or communicate with us, we may collect:</p>
  <ul>
    <li>Name</li>
    <li>Email address</li>
    <li>Phone number</li>
    <li>Reservation details</li>
    <li>Payment-related details (processed securely by third-party providers)</li>
    <li>Any information you voluntarily submit</li>
  </ul>

  <h3>b) Non-Personal Information</h3>
  <p>We may automatically collect:</p>
  <ul>
    <li>IP address</li>
    <li>Browser type and device information</li>
    <li>Pages visited and time spent on our site</li>
    <li>Referring URLs</li>
  </ul>

  <h2>2. How We Use Your Information</h2>
  <p>Your information may be used to:</p>
  <ul>
    <li>Process reservations and respond to inquiries</li>
    <li>Provide customer service and support</li>
    <li>Improve our website and dining experience</li>
    <li>Send confirmations, updates, or promotional offers (if opted in)</li>
    <li>Maintain security and prevent fraud</li>
  </ul>

  <h2>3. Cookies and Tracking Technologies</h2>
  <p>We use cookies and similar technologies to enhance user experience, analyze traffic, and improve site performance. You can disable cookies through your browser settings.</p>

  <h2>4. Payments and Third-Party Services</h2>
  <p>Online payments, reservations, or analytics may be handled by trusted third-party providers. We do not store your credit/debit card details. These providers are required to protect your information and use it only for authorized purposes.</p>

  <h2>5. How We Protect Your Information</h2>
  <p>We use reasonable administrative, technical, and physical safeguards to protect your personal data. However, no internet transmission is completely secure.</p>

  <h2>6. Sharing of Information</h2>
  <p>We do not sell or rent your personal data. Information may only be shared:</p>
  <ul>
    <li>To comply with legal obligations</li>
    <li>To protect our rights or safety</li>
    <li>With service providers assisting our operations</li>
  </ul>

  <h2>7. Third-Party Links</h2>
  <p>Our website may contain links to third-party websites. We are not responsible for their privacy practices.</p>

  <h2>8. Data Retention</h2>
  <p>We retain information only as long as necessary to fulfill business and legal purposes.</p>

  <h2>9. Your Rights</h2>
  <p>You may request access, correction, or deletion of your personal data by contacting us.</p>

  <h2>10. Children's Privacy</h2>
  <p>Our services are not intended for children under 13. We do not knowingly collect data from minors.</p>

  <h2>11. Changes to This Policy</h2>
  <p>This Privacy Policy may be updated periodically. Changes will be posted on this page.</p>

  <h2>12. Contact Us</h2>
  <p><strong>Tribal Sand</strong><br>
  Website: <a href="https://tribalsand.com/">https://tribalsand.com/</a><br>
  Email: <a href="mailto:reservations@tribalsand.com">reservations@tribalsand.com</a></p>

  <p class="legal-updated">Last reviewed: April 2026</p>

</div>
<!-- /LEGAL CONTENT -->

<?php include 'includes/footer.php'; ?>
</body>
