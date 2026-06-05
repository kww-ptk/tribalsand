<?php require_once 'includes/schema.php'; ?>
<?php
$page_title  = 'Smoke-Free Facility Policy · Tribal Sand';
$page_desc   = 'Tribal Sand is a 100% smoke-free hospitality group. Read our smoke-free facility policy covering all properties and grounds.';
$page_url    = 'https://tribalsand.com/sffp.php';
$page_schema = ts_schema_org() . ts_schema_breadcrumb([
    ['name' => 'Home',             'url' => 'https://tribalsand.com/'],
    ['name' => 'Smoke-Free Policy','url' => 'https://tribalsand.com/sffp.php'],
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
  <p class="legal-eyebrow">Policies</p>
  <h1 class="legal-h1">Smoke-Free Facility Policy</h1>
</section>

<div class="legal-wrap">

  <h2>Policy Statement</h2>
  <p>Tribal Sand is committed to providing a healthy, clean, and sustainable environment for all guests, staff, and visitors. In alignment with our core values of environmental conservation and guest wellbeing, all Tribal Sand properties—including My Amani, Maya Kobe, Enkare, Sandbox, Maya Ilai, Off Duty and Zuri—are designated as 100% smoke-free facilities.</p>
  <p>This policy prohibits smoking of any kind in all indoor and outdoor areas of our properties.</p>

  <h2>Scope</h2>
  <p>This policy applies to:</p>
  <ul>
    <li>All guests, visitors, contractors, and staff members</li>
    <li>All Tribal Sand properties and their grounds</li>
    <li>All forms of tobacco products including cigarettes, cigars, pipes, hookahs, and any smoke-producing devices</li>
    <li>Smokeless vaping devices are permitted</li>
    <li>All indoor spaces, outdoor areas, beaches, gardens, terraces, balconies, and common areas</li>
  </ul>

  <h2>Prohibited Activities</h2>
  <p>The following activities are strictly prohibited on all Tribal Sand properties:</p>
  <ul>
    <li>Smoking of cigarettes, cigars, or pipes of any kind</li>
    <li>Use of hookahs, shishas, or water pipes</li>
    <li>Smoking of cannabis or any other substances</li>
    <li>Use of any electronic devices that produce visible smoke or vapor clouds</li>
    <li>Smoking in vehicles on property grounds</li>
  </ul>

  <h2>Permitted Activities</h2>
  <p>Smokeless vaping devices that do not produce visible smoke or vapor are permitted.</p>

  <h2>Rationale</h2>
  <p>This policy is implemented for the following reasons:</p>
  <ul>
    <li><strong>Health and Safety:</strong> Protecting guests and staff from secondhand smoke exposure</li>
    <li><strong>Environmental Conservation:</strong> Cigarette butts are the most prevalent form of beach litter worldwide. Our coastal properties are committed to marine and beach conservation</li>
    <li><strong>Guest Experience:</strong> Ensuring a clean and fresh environment for all guests</li>
    <li><strong>Alignment with Values:</strong> Consistent with our sustainability and wellness ethos</li>
  </ul>

  <h2>Enforcement</h2>
  <ul>
    <li>Guests found smoking in prohibited areas will be asked to stop immediately</li>
    <li>Repeated violations may result in additional cleaning charges or, in serious cases, early termination of stay</li>
    <li>Staff members violating this policy will face disciplinary action in line with our HR policies</li>
  </ul>

  <h2>Guest Acknowledgment</h2>
  <p>All guests are informed of this policy at the time of booking and upon check-in. By confirming a reservation, guests agree to comply with this policy.</p>

  <p>For questions about this policy, contact: <a href="mailto:reservations@tribalsand.com">reservations@tribalsand.com</a></p>

  <p class="legal-updated">Last reviewed: April 2026</p>

</div>
<!-- /LEGAL CONTENT -->

<?php include 'includes/footer.php'; ?>
</body>
