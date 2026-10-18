<?php
/**
 * Impressum for the Wilhelmsburger Ruderclub efaCloud instance.
 * Content adapted from https://www.wrc1895.de/impressum/
 */

$user_requested_file = __FILE__;
include_once "../classes/init.php";
include_once "../classes/splash_hub.php";

Splash_hub::begin_page($menu);
Splash_hub::open_hub(true);
?>
<article class="legal-page">
  <h1><?= i("rhfArk|Imprint") ?></h1>

  <h2>Verantwortlich</h2>
  <p>
    Wilhelmsburger Ruder Club<br>
    Vogelhüttendeich 120<br>
    21107 Hamburg
  </p>
  <p>
    Kontakt: Wilhelmsburger Ruder Club<br>
    E-Mail: <a href="mailto:info@wrc1895.de">info@wrc1895.de</a>
  </p>
  <p>
    Administrator: Thomas Kosinski<br>
    E-Mail: <a href="mailto:admin@wrc1895.de">admin@wrc1895.de</a>
  </p>

  <h2>Registereintrag</h2>
  <p>
    Eintragung ins Vereinsregister<br>
    Vereinsgericht: Hamburg<br>
    Registernummer: 5896
  </p>

  <h2>Umsatzsteuer-ID</h2>
  <p>
    Umsatzsteuer-Identifikationsnummer:<br>
    [bitte eintragen]
  </p>

  <h2>Aufsichtsbehörde</h2>
  <p>Hamburger Sportbund</p>

  <h2>Software</h2>
  <p>
    <?= i("fK4nRd|Club fork of %1.",
            "<a href='https://www.efacloud.org/' rel='noopener noreferrer' target='_blank'>efaCloud</a>") ?>
  </p>

  <p>
    <a href="https://www.wrc1895.de/impressum/" rel="noopener noreferrer" target="_blank">
      <?= i("iM3pQr|Imprint on the club website") ?>
    </a>
  </p>
</article>
<?= Splash_hub::render_back_home() ?>
<?php
Splash_hub::close_hub();
end_script();
