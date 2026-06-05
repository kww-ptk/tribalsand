<style>
/* ── TRIBAL SAND FOOTER ── */
.ts-footer{
  background:var(--ts-teal-d, #102F3A);
  font-family:'Jost',sans-serif;
  border-top:1px solid rgba(184,150,90,.12);
}

/* ── TOP SECTION ── */
.ts-footer-top{
  display:grid;
  grid-template-columns:1.6fr 1fr 1fr 1fr 1fr;
  gap:3rem;
  padding:5rem 5vw 4rem;
  max-width:1300px;
  margin:0 auto;
}

/* Brand column */
.ts-foot-logo{
  height:48px;width:auto;
  filter:brightness(0) invert(1);
  opacity:.75;
  margin-bottom:1.6rem;
  display:block;
}
.ts-foot-desc{
  font-size:.78rem;color:rgba(255,255,255,.55);
  line-height:1.88;max-width:260px;
  margin-bottom:1.6rem;
}
.ts-foot-contact a{
  display:flex;align-items:center;gap:.55rem;
  font-size:.74rem;color:rgba(184,150,90,.8);
  margin-bottom:.5rem;text-decoration:none;
  transition:color .2s;letter-spacing:.04em;
}
.ts-foot-contact a:hover{color:#D4B07A;}
.ts-foot-contact i{font-size:.7rem;width:14px;flex-shrink:0;}
.ts-foot-social{display:flex;gap:.75rem;margin-top:1.4rem;}
.ts-foot-social a{
  width:32px;height:32px;
  display:flex;align-items:center;justify-content:center;
  border:1px solid rgba(184,150,90,.18);
  color:rgba(255,255,255,.3);font-size:.72rem;
  transition:all .2s;text-decoration:none;
}
.ts-foot-social a:hover{border-color:rgba(184,150,90,.5);color:rgba(184,150,90,.8);}

/* Link columns */
.ts-foot-col-h{
  font-size:.58rem;letter-spacing:.26em;text-transform:uppercase;
  color:rgba(184,150,90,.75);margin-bottom:1.1rem;font-weight:500;
}
.ts-foot-links{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:.1rem;}
.ts-foot-links a{
  font-size:.76rem;color:rgba(255,255,255,.58);
  text-decoration:none;padding:.32rem 0;
  display:block;letter-spacing:.04em;
  transition:color .2s;border-bottom:1px solid rgba(255,255,255,.06);
}
.ts-foot-links a:hover{color:rgba(255,255,255,.95);}
.ts-foot-links li:last-child a{border-bottom:none;}

/* ── DIVIDER ── */
.ts-footer-div{
  height:1px;
  background:linear-gradient(to right, transparent, rgba(184,150,90,.15), transparent);
  max-width:1300px;margin:0 auto;
}

/* ── BOTTOM BAR ── */
.ts-footer-bottom{
  max-width:1300px;margin:0 auto;
  padding:1.4rem 5vw;
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:1rem;
}
.ts-foot-copy{
  font-size:.62rem;color:rgba(255,255,255,.35);
  letter-spacing:.08em;
}
.ts-foot-legal{display:flex;gap:1.5rem;flex-wrap:wrap;}
.ts-foot-legal a{
  font-size:.6rem;color:rgba(255,255,255,.32);
  text-decoration:none;letter-spacing:.08em;
  transition:color .2s;
}
.ts-foot-legal a:hover{color:rgba(255,255,255,.75);}

/* Sub-label inside link columns */
.ts-foot-sub-lbl{
  font-size:.5rem;letter-spacing:.24em;text-transform:uppercase;
  color:rgba(184,150,90,.45);margin:1rem 0 .4rem;display:block;
}

/* ── TRUST SIGNAL ── */
.ts-trust{
  display:flex;align-items:center;gap:.75rem;
  background:rgba(255,255,255,.04);
  border:1px solid rgba(184,150,90,.15);
  padding:.65rem .9rem;
  margin-top:1.4rem;
  max-width:260px;
  cursor:default;
}
.ts-trust-dot{
  width:9px;height:9px;border-radius:50%;
  background:#4CAF82;flex-shrink:0;
  animation:ts-pulse 2s ease infinite;
}
@keyframes ts-pulse{
  0%,100%{box-shadow:0 0 0 0 rgba(76,175,130,.5);}
  50%{box-shadow:0 0 0 6px rgba(76,175,130,0);}
}

.ts-trust-text{}
.ts-trust-main{
  font-family:'Jost',sans-serif;
  font-size:.66rem;font-weight:500;
  color:rgba(255,255,255,.88);
  letter-spacing:.03em;line-height:1.3;
}
.ts-trust-main strong{color:#D4B07A;}
.ts-trust-sub{
  font-family:'Jost',sans-serif;
  font-size:.56rem;color:rgba(255,255,255,.35);
  letter-spacing:.06em;margin-top:.12rem;
}


/* ── RESPONSIVE ── */
@media(max-width:1100px){
  .ts-footer-top{grid-template-columns:1fr 1fr 1fr;gap:2rem;}
}
@media(max-width:700px){
  .ts-footer-top{grid-template-columns:1fr 1fr;gap:1.8rem;}
}
@media(max-width:600px){
  .ts-footer-top{grid-template-columns:1fr;padding:3.5rem 5vw 2.5rem;}
  .ts-footer-bottom{flex-direction:column;align-items:flex-start;gap:.75rem;}
  .ts-foot-legal{gap:1rem;}
}
</style>

<footer class="ts-footer">

  <div class="ts-footer-top">

    <!-- Brand -->
    <div>
      <img src="images/footerlogo.png" alt="Tribal Sand" class="ts-foot-logo">
      <p class="ts-foot-desc">A collection of luxury beachfront boutique hotels and private villas on Kenya's North Coast. Watamu · Kilifi · Vipingo.</p>
      <div class="ts-foot-contact">
        <a href="tel:+254115115247"><i class="fas fa-phone"></i>+254 115 115 247</a>
        <a href="mailto:reservations@tribalsand.com"><i class="fas fa-envelope"></i>reservations@tribalsand.com</a>
        <a href="https://wa.me/254115115247" target="_blank"><i class="fab fa-whatsapp"></i>WhatsApp Us</a>
      </div>
      <div class="ts-foot-social">
        <a href="https://www.facebook.com/tribalsand/" target="_blank" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
        <a href="https://www.instagram.com/tribalsand/" target="_blank" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
        <a href="https://www.youtube.com/@tribalsand7436" target="_blank" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
      </div>

      <!-- Trust signal -->
      <div class="ts-trust" id="tsTrust">
        <div class="ts-trust-dot"></div>
        <div class="ts-trust-text">
          <div class="ts-trust-main" id="tsTrustMsg">Booked <strong>7 times</strong> in the last 24h</div>
          <div class="ts-trust-sub">tribalsand.com · Kenya Coast</div>
        </div>
      </div>
    </div>

    <!-- Boutique Hotels + Private Villas -->
    <div>
      <div class="ts-foot-col-h">Boutique Hotels</div>
      <ul class="ts-foot-links">
        <li><a href="zuri.php">Zuri · Watamu</a></li>
        <li><a href="maya-kobe.php">Maya Kobe · Kilifi</a></li>
        <li><a href="maya_ilai.php">Maya Ilai · Kilifi</a></li>
        <li><a href="#" style="color:rgba(184,150,90,.4);cursor:default;">Off Duty · Kilifi <span style="font-size:.54rem;letter-spacing:.1em;color:rgba(184,150,90,.4);">— Coming Soon</span></a></li>
      </ul>
      <span class="ts-foot-sub-lbl">Private Villas</span>
      <ul class="ts-foot-links">
        <li><a href="my-amani.php">My Amani · Vipingo</a></li>
        <li><a href="enkare-bofa.php">Enkare Bofa · Kilifi</a></li>
        <li><a href="sandbox.php">Sandbox · Kilifi</a></li>
      </ul>
    </div>

    <!-- Tribal Dunes -->
    <div>
      <div class="ts-foot-col-h">Tribal Dunes</div>
      <ul class="ts-foot-links">
        <li><a href="maya-kobe.php">Maya Kobe</a></li>
        <li><a href="maya_ilai.php">Maya Ilai</a></li>
        <li><a href="#">Off Duty <span style="font-size:.54rem;color:rgba(184,150,90,.4);">— Soon</span></a></li>
        <li><a href="maya-kobe.php#tribal-table">Tribal Table <span style="font-size:.54rem;color:rgba(184,150,90,.4);">— Soon</span></a></li>
        <li><a href="maya-kobe.php#somewhere-cafe">Somewhere Café <span style="font-size:.54rem;color:rgba(184,150,90,.4);">— Soon</span></a></li>
        <li><a href="http://tribalkiteschool.com/" target="_blank">Kite School</a></li>
        <li><a href="https://tribalsand.com/tribalsand-blog-tribal-dunes.html">Read the Story →</a></li>
      </ul>
    </div>

    <!-- Discover -->
    <div>
      <div class="ts-foot-col-h">Discover</div>
      <ul class="ts-foot-links">
        <li><a href="activities.php">Activities</a></li>
        <li><a href="http://tribalkiteschool.com/" target="_blank">Kite School</a></li>
        <li><a href="events.php">Events</a></li>
        <li><a href="https://tribalsand.com/tribalsand-blog-tribal-dunes.html">Tribal Dunes</a></li>
        <li><a href="sustainability.php">Sustainability</a></li>
        <li><a href="blog.php">Blog</a></li>
      </ul>
    </div>

    <!-- Company -->
    <div>
      <div class="ts-foot-col-h">Company</div>
      <ul class="ts-foot-links">
        <li><a href="tribalsandstory.php">Our Story</a></li>
        <li><a href="contact.php">Contact Us</a></li>
        <li><a href="for-agents.php">For Agents</a></li>
        <li><a href="https://book.tribalsand.com/booking/chain-tribalsand-en" target="_blank">Book Now</a></li>
        <li><a href="trip-builder.php">Plan Your Trip</a></li>
      </ul>
    </div>

  </div>

  <div class="ts-footer-div"></div>

  <div class="ts-footer-bottom">
    <div class="ts-foot-copy">© 2026 Tribalsand LLC · All Rights Reserved · Tribalsand is a U.S.-based company</div>
    <div class="ts-foot-legal">
      <a href="privacy_policy.php" target="_blank">Privacy Policy</a>
      <a href="sffp.php" target="_blank">Smoke-Free Policy</a>
      <a href="tc.php" target="_blank">Terms & Conditions</a>
      <a href="licences.php" target="_blank">Licences</a>
    </div>
  </div>

</footer>

<script>
(function(){
  var msgs = [
    'Booked <strong>7 times</strong> in the last 24h',
    '<strong>3 people</strong> viewing properties now',
    'Booked <strong>4 times</strong> this week',
    '<strong>My Amani</strong> just got an enquiry',
    '<strong>Zuri</strong> is popular this season',
    'Last booking <strong>2 hours ago</strong>',
    '<strong>5 enquiries</strong> received today',
  ];
  var el = document.getElementById('tsTrustMsg');
  if(!el) return;
  var i = 0;
  setInterval(function(){
    i = (i + 1) % msgs.length;
    el.style.opacity = '0';
    el.style.transition = 'opacity .3s';
    setTimeout(function(){ el.innerHTML = msgs[i]; el.style.opacity = '1'; }, 300);
  }, 8000);
})();
</script>

<!-- LeadConnector chat widget — kept from original -->
<script src="https://widgets.leadconnectorhq.com/loader.js"
  data-resources-url="https://widgets.leadconnectorhq.com/chat-widget/loader.js"
  data-widget-id="691f01ab467a1f787a2fa6f9">
</script>
