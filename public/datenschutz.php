<?php
/**
 * Datenschutzerklärung for the Wilhelmsburger Ruderclub efaCloud instance.
 * Base text adapted from https://www.wrc1895.de/datenschutzerklärung/,
 * rewritten for efaCloud admin, Portal and Statistik (Umami instead of Google Analytics).
 */

$user_requested_file = __FILE__;
include_once "../classes/init.php";
include_once "../classes/splash_hub.php";

Splash_hub::begin_page($menu);
Splash_hub::open_hub(true);
?>
<article class="legal-page">
  <h1><?= i("dS9wFx|Privacy policy") ?></h1>

  <h2>1. Datenschutz auf einen Blick</h2>

  <h3>Wer ist verantwortlich für die Datenerfassung?</h3>
  <p>
    Die Datenverarbeitung auf diesem efaCloud-Angebot (Verwaltung, Portal und Statistik)
    erfolgt durch den Betreiber. Dessen Kontaktdaten können Sie dem
    <a href="../public/impressum.php"><?= i("rhfArk|Imprint") ?></a> entnehmen.
  </p>

  <h3>Wie erfassen wir Ihre Daten?</h3>
  <p>
    Ihre Daten werden zum einen dadurch erhoben, dass Sie uns diese mitteilen —
    etwa bei der Anmeldung mit Ihrem efaCloud-Konto (Benutzerkennung, E-Mail oder Kontoname und Kennwort).
  </p>
  <p>
    Andere Daten werden automatisch beim Besuch der Website durch unsere IT-Systeme erfasst.
    Das sind vor allem technische Daten (z.&nbsp;B. Internetbrowser, Betriebssystem, Uhrzeit des Seitenaufrufs,
    IP-Adresse in Server-Logdateien). Die Erfassung dieser Daten erfolgt automatisch, sobald Sie unsere Website betreten.
  </p>

  <h3>Wofür nutzen wir Ihre Daten?</h3>
  <p>
    Ein Teil der Daten wird erhoben, um eine fehlerfreie Bereitstellung der Website und der Anwendungen
    (Anmeldung, Fahrtenbuch-Portal, Vereinsstatistik) zu gewährleisten.
    Andere Daten können zur anonymisierten Auswertung der Nutzung (Umami) verwendet werden.
  </p>

  <h3>Welche Rechte haben Sie bezüglich Ihrer Daten?</h3>
  <p>
    Sie haben jederzeit das Recht, unentgeltlich Auskunft über Herkunft, Empfänger und Zweck Ihrer gespeicherten
    personenbezogenen Daten zu erhalten. Sie haben außerdem ein Recht auf Berichtigung, Sperrung oder Löschung dieser Daten.
    Hierzu sowie zu weiteren Fragen zum Thema Datenschutz können Sie sich jederzeit unter der im Impressum angegebenen
    Adresse an uns wenden. Des Weiteren steht Ihnen ein Beschwerderecht bei der zuständigen Aufsichtsbehörde zu.
  </p>

  <h2>2. Allgemeine Hinweise und Pflichtinformationen</h2>

  <h3>Datenschutz</h3>
  <p>
    Die Betreiber dieser Seiten nehmen den Schutz Ihrer persönlichen Daten sehr ernst.
    Wir behandeln Ihre personenbezogenen Daten vertraulich und entsprechend der gesetzlichen Datenschutzvorschriften
    sowie dieser Datenschutzerklärung.
  </p>
  <p>
    Wenn Sie diese Website benutzen, werden verschiedene personenbezogene Daten erhoben.
    Personenbezogene Daten sind Daten, mit denen Sie persönlich identifiziert werden können.
    Die vorliegende Datenschutzerklärung erläutert, welche Daten wir erheben und wofür wir sie nutzen.
  </p>
  <p>
    Wir weisen darauf hin, dass die Datenübertragung im Internet (z.&nbsp;B. bei der Kommunikation per E-Mail)
    Sicherheitslücken aufweisen kann. Ein lückenloser Schutz der Daten vor dem Zugriff durch Dritte ist nicht möglich.
  </p>

  <h3>Hinweis zur verantwortlichen Stelle</h3>
  <p>
    Die verantwortliche Stelle für die Datenverarbeitung auf dieser Website ist:
  </p>
  <p>
    Wilhelmsburger Ruder Club e.V.<br>
    Vogelhüttendeich 120<br>
    21107 Hamburg<br>
    E-Mail: <a href="mailto:info@wrc1895.de">info@wrc1895.de</a>
  </p>
  <p>
    Verantwortliche Stelle ist die natürliche oder juristische Person, die allein oder gemeinsam mit anderen
    über die Zwecke und Mittel der Verarbeitung von personenbezogenen Daten (z.&nbsp;B. Namen, E-Mail-Adressen o.&nbsp;Ä.) entscheidet.
  </p>

  <h3>Widerruf Ihrer Einwilligung zur Datenverarbeitung</h3>
  <p>
    Viele Datenverarbeitungsvorgänge sind nur mit Ihrer ausdrücklichen Einwilligung möglich.
    Sie können eine bereits erteilte Einwilligung jederzeit widerrufen. Dazu reicht eine formlose Mitteilung per E-Mail an uns.
    Die Rechtmäßigkeit der bis zum Widerruf erfolgten Datenverarbeitung bleibt vom Widerruf unberührt.
  </p>

  <h3>Beschwerderecht bei der zuständigen Aufsichtsbehörde</h3>
  <p>
    Im Falle datenschutzrechtlicher Verstöße steht dem Betroffenen ein Beschwerderecht bei der zuständigen Aufsichtsbehörde zu.
    Zuständige Aufsichtsbehörde in datenschutzrechtlichen Fragen ist der Hamburgische Beauftragte für Datenschutz und Informationsfreiheit.
    Weitere Informationen: <a href="https://www.bfdi.bund.de/" rel="noopener noreferrer" target="_blank">www.bfdi.bund.de</a>.
  </p>

  <h3>Recht auf Datenübertragbarkeit</h3>
  <p>
    Sie haben das Recht, Daten, die wir auf Grundlage Ihrer Einwilligung oder in Erfüllung eines Vertrags automatisiert verarbeiten,
    an sich oder an einen Dritten in einem gängigen, maschinenlesbaren Format aushändigen zu lassen.
    Sofern Sie die direkte Übertragung der Daten an einen anderen Verantwortlichen verlangen, erfolgt dies nur, soweit es technisch machbar ist.
  </p>

  <h3>SSL- bzw. TLS-Verschlüsselung</h3>
  <p>
    Diese Seite nutzt aus Sicherheitsgründen und zum Schutz der Übertragung vertraulicher Inhalte eine SSL- bzw. TLS-Verschlüsselung.
    Eine verschlüsselte Verbindung erkennen Sie daran, dass die Adresszeile des Browsers von „http://“ auf „https://“ wechselt
    und an dem Schloss-Symbol in Ihrer Browserzeile.
  </p>

  <h3>Auskunft, Sperrung, Löschung</h3>
  <p>
    Sie haben im Rahmen der geltenden gesetzlichen Bestimmungen jederzeit das Recht auf unentgeltliche Auskunft über Ihre gespeicherten
    personenbezogenen Daten, deren Herkunft und Empfänger und den Zweck der Datenverarbeitung und ggf. ein Recht auf Berichtigung,
    Sperrung oder Löschung dieser Daten. Hierzu sowie zu weiteren Fragen zum Thema personenbezogene Daten können Sie sich jederzeit
    unter der im Impressum angegebenen Adresse an uns wenden.
  </p>

  <h3>Widerspruch gegen Werbe-Mails</h3>
  <p>
    Der Nutzung von im Rahmen der Impressumspflicht veröffentlichten Kontaktdaten zur Übersendung von nicht ausdrücklich angeforderter
    Werbung und Informationsmaterialien wird hiermit widersprochen. Die Betreiber der Seiten behalten sich ausdrücklich rechtliche Schritte
    im Falle der unverlangten Zusendung von Werbeinformationen, etwa durch Spam-E-Mails, vor.
  </p>

  <h2>3. Datenerfassung auf diesem efaCloud-Angebot</h2>

  <h3>Cookies und Sitzungen</h3>
  <p>
    Diese Anwendungen verwenden technisch notwendige Cookies bzw. Sitzungsdaten, damit Anmeldung und sichere Nutzung möglich sind:
  </p>
  <ul>
    <li>PHP-Sitzungscookie für die efaCloud-Verwaltungsoberfläche</li>
    <li>Sitzungscookie <code>EFA_PORTAL</code> für das Fahrtenbuch-Portal und die Statistik (HttpOnly, SameSite=Strict, Secure bei HTTPS)</li>
  </ul>
  <p>
    Diese Cookies sind für die von Ihnen gewünschte Funktion (Anmeldung und Sitzungsschutz) erforderlich und werden auf Grundlage von
    Art.&nbsp;6 Abs.&nbsp;1 lit.&nbsp;b und lit.&nbsp;f DSGVO gespeichert. Ohne sie ist eine Anmeldung nicht möglich.
  </p>

  <h3>Anmeldung und Nutzerkonto</h3>
  <p>
    Zur Nutzung von Verwaltung, Portal und Statistik melden Sie sich mit Ihrem efaCloud-Konto an.
    Dabei werden Kennung und Passwort (bzw. Einmalpasswort) geprüft. Passwörter werden nicht im Klartext gespeichert,
    sondern als kryptografischer Hash. Grundlage ist Art.&nbsp;6 Abs.&nbsp;1 lit.&nbsp;b DSGVO (Erfüllung der Mitgliedschafts- bzw. Nutzungsvereinbarung)
    sowie lit.&nbsp;f (berechtigtes Interesse an einer sicheren Authentifizierung).
  </p>

  <h3>Server-Log-Dateien</h3>
  <p>
    Der Provider der Seiten erhebt und speichert automatisch Informationen in so genannten Server-Log-Dateien,
    die Ihr Browser automatisch an uns übermittelt. Dies sind:
  </p>
  <ul>
    <li>Browsertyp und Browserversion</li>
    <li>verwendetes Betriebssystem</li>
    <li>Referrer URL</li>
    <li>Hostname des zugreifenden Rechners</li>
    <li>Uhrzeit der Serveranfrage</li>
    <li>IP-Adresse</li>
  </ul>
  <p>
    Eine Zusammenführung dieser Daten mit anderen Datenquellen wird nicht vorgenommen.
    Grundlage für die Datenverarbeitung ist Art.&nbsp;6 Abs.&nbsp;1 lit.&nbsp;f DSGVO (berechtigtes Interesse an einem sicheren und stabilen Betrieb).
  </p>

  <h2>4. Analyse-Tools</h2>

  <h3>Umami</h3>
  <p>
    Diese Website nutzt den datenschutzfreundlichen Webanalysedienst Umami.
    Das Analyseskript wird von <code>umami.project-park.de</code> geladen
    (Website-ID <code>3a0e8d3b-4e4d-4830-bbe2-86396391dae1</code>).
  </p>
  <p>
    Umami dient dazu, Seitenaufrufe und grundlegende Nutzungsstatistiken zu erfassen (z.&nbsp;B. aufgerufene Seiten, Referrer, Gerätetyp),
    ohne Nutzerprofile zu erstellen und ohne Daten an Werbenetzwerke weiterzugeben.
    Die Verarbeitung erfolgt auf Grundlage von Art.&nbsp;6 Abs.&nbsp;1 lit.&nbsp;f DSGVO (berechtigtes Interesse an der Verbesserung dieses Angebots).
  </p>
  <p>
    Weitere Informationen zum Umami-Dienst erhalten Sie beim Betreiber der Instanz unter
    <a href="https://umami.project-park.de/" rel="noopener noreferrer" target="_blank">umami.project-park.de</a>.
  </p>

  <h2>5. Weitere Hinweise</h2>
  <p>
    Die Datenschutzerklärung der Vereinswebsite finden Sie unter
    <a href="https://www.wrc1895.de/datenschutzerkl%C3%A4rung/" rel="noopener noreferrer" target="_blank">wrc1895.de/datenschutzerklärung</a>.
    Diese Seite bezieht sich auf das efaCloud-Angebot (Verwaltung, Portal und Statistik) des Wilhelmsburger Ruder Clubs.
  </p>
</article>
<?= Splash_hub::render_back_home() ?>
<?php
Splash_hub::close_hub();
end_script();
