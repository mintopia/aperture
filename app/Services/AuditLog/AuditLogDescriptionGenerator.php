<?php

declare(strict_types=1);

namespace App\Services\AuditLog;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchConfig;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Generates human-readable descriptions for audit log entries.
 */
final class AuditLogDescriptionGenerator
{
    public static function generate(AuditLog $log): string
    {
        return match ($log->action) {
            'user.login' => sprintf('%s logged in', self::subjectLabel($log)),
            'user.login_failed' => sprintf('Failed login attempt for %s', self::metadataString($log, 'email', 'an unknown user')),
            'user.logout' => sprintf('%s logged out', self::subjectLabel($log)),
            'user.captive_login' => sprintf('%s logged in via the captive portal', self::subjectLabel($log)),
            'user.password_created' => sprintf('%s set a password', self::subjectLabel($log)),
            'user.password_changed' => sprintf('%s changed their password', self::subjectLabel($log)),
            'user.password_cleared' => sprintf('%s cleared their password', self::subjectLabel($log)),
            'user.passkey_registered' => sprintf('%s registered a passkey', self::subjectLabel($log)),
            'user.passkey_deleted' => sprintf('%s deleted a passkey', self::subjectLabel($log)),
            'user.role_changed' => sprintf('Changed roles for %s to %s', self::subjectLabel($log), self::metadataList($log, 'roles')),
            'user.block_toggled' => sprintf('%s internet for %s', self::metadataBool($log, 'blocked') ? 'Blocked' : 'Unblocked', self::subjectLabel($log)),
            'user.internet_toggled', 'ip.internet_toggled' => sprintf('%s internet for %s', self::toggleWord($log), self::subjectLabel($log)),
            'user.dns_filter_toggled', 'ip.dns_filter_toggled' => sprintf('%s DNS filtering for %s', self::toggleWord($log), self::subjectLabel($log)),
            'ip.rate_limit_toggled' => sprintf('%s rate limiting for %s', self::toggleWord($log), self::subjectLabel($log)),
            'ip.session_expired' => sprintf('Session expired for %s, internet disabled', self::subjectLabel($log)),
            'ip.created' => sprintf('Discovered new IP %s via %s', self::subjectLabel($log), self::metadataString($log, 'source', $log->process)),
            'ip.user_cascaded' => sprintf('Assigned %s to %s via %s', self::subjectLabel($log), self::actorLabel($log), self::relatedLabel($log)),
            'mac.created' => sprintf('Discovered new device %s via %s', self::subjectLabel($log), self::metadataString($log, 'source', $log->process)),
            'mac.user_assigned' => sprintf('Assigned %s to %s', self::subjectLabel($log), self::actorLabel($log)),
            'ip_mac.linked' => sprintf('Linked %s to %s via %s', self::relatedLabel($log), self::subjectLabel($log), $log->process),
            'port_mac.linked' => sprintf('Linked %s to a switch port', self::metadataString($log, 'mac', self::subjectLabel($log))),
            'oui.auto_allowed' => sprintf('Automatically enabled internet for %s (%s) by OUI policy', self::subjectLabel($log), self::relatedLabel($log)),
            'switch.created' => sprintf('Created switch %s', self::subjectLabel($log)),
            'switch.updated' => sprintf('Updated switch %s', self::subjectLabel($log)),
            'switch.deleted' => sprintf('Deleted switch %s', self::subjectLabel($log)),
            'settings.updated' => sprintf('Updated %s settings', self::metadataString($log, 'setting_group', 'application')),
            'integration.updated' => sprintf('Updated %s integration settings', self::metadataString($log, 'service', 'unknown')),
            'portal.reset' => sprintf('%s reset the portal', self::actorLabel($log)),
            'switch.unreachable' => sprintf('Switch %s unreachable after %s failures', self::subjectLabel($log), self::metadataString($log, 'failure_count', '?')),
            'bandwidth.anomaly' => sprintf('Bandwidth anomaly detected on %s', self::metadataString($log, 'ip_address', 'unknown')),
            'dhcp.threshold_reached' => sprintf('DHCP pool %s reached %d%% utilisation', self::metadataString($log, 'pool', 'unknown'), self::metadataInt($log, 'usage', 0)),
            default => self::fallback($log),
        };
    }

    private static function fallback(AuditLog $log): string
    {
        $description = ucfirst(str_replace(['_', '.'], ' ', $log->action));

        if ($log->subject_type !== null) {
            $description .= ' '.self::subjectLabel($log);
        }

        if ($log->related_type !== null) {
            $description .= ' → '.self::relatedLabel($log);
        }

        return $description;
    }

    private static function subjectLabel(AuditLog $log): string
    {
        return self::label($log->subject_type, $log->subject, $log->subject_id);
    }

    private static function relatedLabel(AuditLog $log): string
    {
        return self::label($log->related_type, $log->related, $log->related_id);
    }

    private static function actorLabel(AuditLog $log): string
    {
        return self::label($log->actor_type, $log->actor, $log->actor_id);
    }

    private static function label(?string $type, ?Model $model, ?int $id): string
    {
        if ($model instanceof MacAddress) {
            return $model->mac_address;
        }

        if ($model instanceof IpAddress) {
            return $model->address;
        }

        if ($model instanceof User) {
            return $model->nickname !== '' ? $model->nickname : $model->email;
        }

        if ($model instanceof SwitchConfig) {
            return $model->name;
        }

        if ($type === null) {
            return 'unknown';
        }

        return class_basename($type).' #'.($id ?? '?');
    }

    private static function toggleWord(AuditLog $log): string
    {
        return self::metadataBool($log, 'enabled') ? 'Enabled' : 'Disabled';
    }

    private static function metadataBool(AuditLog $log, string $key): bool
    {
        return ($log->metadata[$key] ?? false) === true;
    }

    private static function metadataString(AuditLog $log, string $key, string $default): string
    {
        $value = $log->metadata[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return $default;
    }

    private static function metadataInt(AuditLog $log, string $key, int $default): int
    {
        $value = $log->metadata[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    private static function metadataList(AuditLog $log, string $key): string
    {
        $value = $log->metadata[$key] ?? null;

        if (! is_array($value) || $value === []) {
            return 'none';
        }

        return implode(', ', array_map(
            static fn (mixed $item): string => is_scalar($item) ? (string) $item : '?',
            $value,
        ));
    }
}
