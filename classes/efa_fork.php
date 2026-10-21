<?php
/**
 * Club-fork identity for support and contact (not upstream efacloud.org).
 */
class Efa_fork
{

    /** Support inbox for this fork (Thies Möller). */
    public static function support_email (): string
    {
        return "thies.moeller@gmail.com";
    }

    /** Human name shown next to the support address. */
    public static function support_name (): string
    {
        return "Thies Möller";
    }

    /** mailto: URI for fallback / help links. */
    public static function support_mailto (): string
    {
        return "mailto:" . self::support_email();
    }

    /** Public issue tracker for this fork. */
    public static function support_issues_url (): string
    {
        return "https://github.com/thiesmoeller/efacloud/issues";
    }

    /** "Name <email>" for mail To: headers. */
    public static function support_mailto_header (): string
    {
        return self::support_name() . " <" . self::support_email() . ">";
    }
}
