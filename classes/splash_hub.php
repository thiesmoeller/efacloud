<?php
/**
 * Shared branded splash chrome for anonymous entry pages (landing, login, legal, help).
 * Frontend-only; does not change auth or backend behaviour.
 */
class Splash_hub
{

    /**
     * Emit the no-sidebar splash document shell (head + main open).
     */
    public static function begin_page ($menu): void
    {
        echo file_get_contents(__DIR__ . '/../config/snippets/page_01_start_no_menu');
        $menu->set_style(3);
        echo $menu->get_menu();
        echo file_get_contents(__DIR__ . '/../config/snippets/page_02_nav_to_body_no_menu');
    }

    /**
     * Open hub + brand + panel. Pass $wide for long-form content (legal, help).
     */
    public static function open_hub (bool $wide = false): void
    {
        $hub_class = $wide ? "splashscreen login-hub legal-hub" : "splashscreen login-hub";
        echo "<div class='" . $hub_class . "'>\n";
        echo self::render_brand();
        echo "<div class='login-panel'>\n";
    }

    /**
     * Close panel + hub, with shared legal footer.
     */
    public static function close_hub (): void
    {
        echo self::render_legal();
        echo "</div></div>\n";
    }

    public static function render_brand (): string
    {
        return "<div class='login-brand'>\n" .
                 "<img class='login-club-logo' src='../resources/logo_verein.png' " .
                 "alt='" . htmlspecialchars(i("mW2hRc|Wilhelmsburger Ruderclub"), ENT_QUOTES) . "'>\n" .
                 "<p class='login-club-name'>" . i("mW2hRc|Wilhelmsburger Ruderclub") . "</p>\n" .
                 "<p class='login-brand-mark'>efa<span>Cloud</span></p>\n" .
                 "<p class='login-tagline'>" . i("tG5bYn|Your club entry to admin, ...") . "</p>\n" .
                 "</div>\n";
    }

    public static function render_apps (): string
    {
        return "<nav class='login-apps' aria-label='" .
                 htmlspecialchars(i("aK7mQp|Club apps"), ENT_QUOTES) . "'>" .
                 "<a class='login-app' href='../portal/'>" .
                 "<span class='login-app-title'>" . i("pR8nWt|Portal") . "</span>" .
                 "<span class='login-app-desc'>" . i("qL3vXs|Dockside logbook") . "</span></a>" .
                 "<a class='login-app' href='../stats/'>" .
                 "<span class='login-app-title'>" . i("sT4yUm|Statistics") . "</span>" .
                 "<span class='login-app-desc'>" . i("uN6zKp|Club fleet insights") . "</span></a>" .
                 "</nav>";
    }

    public static function render_legal (): string
    {
        return "<footer class='login-legal'>" .
                 "<p class='login-fork'>" . i("fK4nRd|Club fork of %1.",
                         "<a href='https://www.efacloud.org/' rel='noopener noreferrer' target='_blank'>efaCloud</a>") .
                 "</p>" .
                 "<a href='../public/impressum.php'>" . i("rhfArk|Imprint") . "</a>" .
                 "<span aria-hidden='true'>·</span>" .
                 "<a href='../public/datenschutz.php'>" . i("dS9wFx|Privacy policy") . "</a>" .
                 "<span aria-hidden='true'>·</span>" .
                 "<a href='https://www.wrc1895.de/' rel='noopener noreferrer' target='_blank'>" .
                 i("wR1cLb|Club website") . "</a>" .
                 "</footer>";
    }

    public static function render_back_home (): string
    {
        return "<p class='login-reset'><a href='../public/index.php'>" .
                 i("bK2nHm|Back to start") . "</a></p>\n";
    }
}
