<?php
/**
 * Structured portal API errors with machine codes and German user-facing messages.
 */

declare(strict_types=1);

class Portal_error extends Exception
{
    public string $code_str;
    public array $details;

    public function __construct(string $code, string $message_de, int $http = 400, array $details = [])
    {
        parent::__construct($message_de, $http);
        $this->code_str = $code;
        $this->details = $details;
    }

    public function to_array(): array
    {
        $out = [
            'error' => [
                'code' => $this->code_str,
                'message' => $this->getMessage(),
            ],
        ];
        if ($this->details !== []) {
            $out['error']['details'] = $this->details;
        }
        return $out;
    }

    public static function unauthorized(string $msg = 'Anmeldung erforderlich.'): self
    {
        return new self('AUTH_REQUIRED', $msg, 401);
    }

    public static function forbidden(string $msg = 'Keine Berechtigung für diese Aktion.'): self
    {
        return new self('FORBIDDEN', $msg, 403);
    }

    public static function csrf(string $msg = 'CSRF-Token ungültig oder fehlt.'): self
    {
        return new self('CSRF_INVALID', $msg, 403);
    }

    public static function not_found(string $msg = 'Eintrag nicht gefunden.'): self
    {
        return new self('NOT_FOUND', $msg, 404);
    }

    public static function conflict(string $code, string $msg, array $details = []): self
    {
        return new self($code, $msg, 409, $details);
    }

    public static function validation(string $code, string $msg, array $details = []): self
    {
        return new self($code, $msg, 422, $details);
    }

    public static function throttle(string $msg = 'Zu viele Anmeldeversuche. Bitte später erneut versuchen.'): self
    {
        return new self('LOGIN_THROTTLED', $msg, 429);
    }

    public static function revoked(string $msg = 'Dieses Konto ist deaktiviert.'): self
    {
        return new self('ACCOUNT_REVOKED', $msg, 403);
    }

    public static function stale(string $msg = 'Der Datensatz wurde zwischenzeitlich geändert. Bitte neu laden.', array $details = []): self
    {
        return new self('STALE_STATE', $msg, 409, $details);
    }

    public static function ack_required(string $kind, string $msg, array $details = []): self
    {
        return new self('ACK_REQUIRED', $msg, 409, array_merge(['acknowledgmentKind' => $kind], $details));
    }

    public static function ack_stale(string $msg = 'Die Bestätigung ist veraltet. Bitte erneut bestätigen.', array $details = []): self
    {
        return new self('ACK_STALE', $msg, 409, $details);
    }
}
