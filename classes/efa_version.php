<?php
/**
 * Upstream efaCloud vs club-fork version helpers.
 *
 * public/version      — last integrated upstream id (e.g. 2.4.0_13), feed-compatible
 * public/fork_version — club build id (e.g. wrc.1), independent of upstream drops
 */
class Efa_version
{

    /** @return string trimmed upstream id from public/version */
    public static function upstream (): string
    {
        $path = __DIR__ . "/../public/version";
        if (! is_readable($path))
            return "";
        return trim((string) file_get_contents($path));
    }

    /** @return string trimmed fork id from public/fork_version */
    public static function fork (): string
    {
        $path = __DIR__ . "/../public/fork_version";
        if (! is_readable($path))
            return "";
        return trim((string) file_get_contents($path));
    }

    /**
     * Sidebar / footer label: upstream first, then fork.
     * Example: "efaCloud 2.4.0_13 · wrc.1"
     */
    public static function display_string (): string
    {
        $up = self::upstream();
        $fork = self::fork();
        if (strlen($up) == 0 && strlen($fork) == 0)
            return " ";
        if (strlen($fork) == 0)
            return "efaCloud " . $up;
        if (strlen($up) == 0)
            return $fork;
        return "efaCloud " . $up . " · " . $fork;
    }

    /**
     * Latest version id from the official scanversions feed, or "" on failure.
     */
    public static function latest_upstream_from_feed (): string
    {
        $own = self::upstream();
        if (strlen($own) == 0)
            $own = "0";
        $url = "https://efacloud.org/src/scanversions.php?own=" . rawurlencode($own);
        $raw = @file_get_contents($url);
        if ($raw === false || strlen(trim($raw)) == 0)
            return "";
        $versions = [];
        foreach (explode("|", $raw) as $v) {
            $v = trim($v);
            if (strlen($v) > 0)
                $versions[] = $v;
        }
        if (count($versions) == 0)
            return "";
        rsort($versions, SORT_STRING);
        return $versions[0];
    }

    /**
     * True when the official feed advertises a newer upstream than we have integrated.
     */
    public static function upstream_update_available (?string &$latest = null): bool
    {
        $latest = self::latest_upstream_from_feed();
        $current = self::upstream();
        if (strlen($latest) == 0 || strlen($current) == 0)
            return false;
        return strcasecmp($latest, $current) != 0;
    }
}
