<?php
/**
 * Shared branded splash chrome for anonymous entry pages (landing, login, legal, help).
 * Frontend-only; does not change auth or backend behaviour.
 */
class Splash_hub
{

    /**
     * Prefer image-bundled snippets over the persistent config volume.
     * CapRover mounts config/ across deploys; new splash snippets would otherwise
     * be missing and pages render as unstyled body HTML (no head / CSS links).
     */
    private static function read_snippet (string $name): string
    {
        $bundled = "/opt/efacloud-defaults/config/snippets/" . $name;
        $volume = __DIR__ . "/../config/snippets/" . $name;
        if (is_readable($bundled)) {
            $html = file_get_contents($bundled);
            if ($html !== false && strlen($html) > 0) {
                return $html;
            }
        }
        $html = @file_get_contents($volume);
        return ($html !== false) ? $html : "";
    }

    /**
     * Emit the no-sidebar splash document shell (head + main open).
     */
    public static function begin_page ($menu): void
    {
        echo self::read_snippet("page_01_start_no_menu");
        $menu->set_style(3);
        echo $menu->get_menu();
        echo self::read_snippet("page_02_nav_to_body_no_menu");
    }

    /**
     * Open hub + brand + panel. Pass $wide for long-form content (legal, help).
     * Pass $dispatcher for the three-tile landing (wider desktop hub).
     * Compact app-switcher stays inside logged-in / React shells — not on splash
     * (landing uses the larger dispatcher tiles instead).
     */
    public static function open_hub (bool $wide = false, bool $dispatcher = false): void
    {
        $hub_class = "splashscreen login-hub";
        if ($wide)
            $hub_class .= " legal-hub";
        if ($dispatcher)
            $hub_class .= " login-hub-dispatch";
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

    /**
     * Compact cross-app switcher (efaCloud / Portal / Stats).
     * $active: cloud | portal | stats
     */
    public static function render_app_switcher (string $active = "cloud"): string
    {
        $items = [
                ["cloud", "/pages/home.php", i("vW3nAd|Admin")],
                ["portal", "/portal/", i("pR8nWt|Portal")],
                ["stats", "/stats/", i("sT4yUm|Statistics")],
        ];
        $html = "<nav class='app-switcher' aria-label='" .
                 htmlspecialchars(i("aK7mQp|Club apps"), ENT_QUOTES) . "'>\n";
        foreach ($items as $item) {
            [$id, $href, $label] = $item;
            $cls = "app-switcher-link" . ($id === $active ? " is-active" : "");
            $aria = ($id === $active) ? " aria-current='page'" : "";
            $html .= "<a class='" . $cls . "' href='" . htmlspecialchars($href, ENT_QUOTES) . "'" .
                     $aria . ">" . htmlspecialchars($label, ENT_QUOTES) . "</a>\n";
        }
        $html .= "</nav>\n";
        return $html;
    }

    /**
     * Landing dispatcher: three equal tiles for Verwaltung / Portal / Statistik.
     */
    public static function render_dispatcher (): string
    {
        return "<nav class='login-apps login-apps-three' aria-label='" .
                 htmlspecialchars(i("aK7mQp|Club apps"), ENT_QUOTES) . "'>" .
                 "<a class='login-app' href='/forms/login.php'>" .
                 "<span class='login-app-title'>" . i("vW3nAd|Admin") . "</span>" .
                 "<span class='login-app-desc'>" . i("yQ1qgk|Start efaCloud") . "</span></a>" .
                 "<a class='login-app' href='/portal/'>" .
                 "<span class='login-app-title'>" . i("pR8nWt|Portal") . "</span>" .
                 "<span class='login-app-desc'>" . i("qL3vXs|Dockside logbook") . "</span></a>" .
                 "<a class='login-app' href='/stats/'>" .
                 "<span class='login-app-title'>" . i("sT4yUm|Statistics") . "</span>" .
                 "<span class='login-app-desc'>" . i("uN6zKp|Club fleet insights") . "</span></a>" .
                 "</nav>";
    }

    /** Portal + Stats tiles (e.g. under the login form). */
    public static function render_apps (): string
    {
        return "<nav class='login-apps' aria-label='" .
                 htmlspecialchars(i("aK7mQp|Club apps"), ENT_QUOTES) . "'>" .
                 "<a class='login-app' href='/portal/'>" .
                 "<span class='login-app-title'>" . i("pR8nWt|Portal") . "</span>" .
                 "<span class='login-app-desc'>" . i("qL3vXs|Dockside logbook") . "</span></a>" .
                 "<a class='login-app' href='/stats/'>" .
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
