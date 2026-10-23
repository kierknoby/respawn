<?php
declare(strict_types=1);
namespace Respawn\Reconstruction;

use RuntimeException;

final class NativeProfile
{
    public static function deviceFixed(string $number, string $description): array
    {
        return ['sipdriver' => 'chan_pjsip', 'account' => $number, 'dial' => 'PJSIP/' . $number,
            'callerid' => $description . ' <' . $number . '>', 'mailbox' => $number . '@device',
            'md5_cred' => '', 'trustrpid' => 'yes', 'sendrpid' => 'pai', 'qualifyfreq' => '60',
            'avpf' => 'no', 'timers' => 'yes', 'icesupport' => 'no', 'send_connected_line' => 'yes',
            'user_eq_phone' => 'no', 'namedcallgroup' => '', 'namedpickupgroup' => '', 'accountcode' => '',
            'media_use_received_transport' => 'no', 'media_address' => '', 'mwi_subscription' => 'auto',
            'aggregate_mwi' => 'no', 'media_encryption_optimistic' => 'no', 'device_state_busy_at' => '0',
            'rtcp_mux' => 'no', 'bundle' => 'no', 'minimum_expiration' => '60', 'maximum_expiration' => '7200',
            'match' => '', 'rtp_timeout' => '0', 'rtp_timeout_hold' => '0', 'timers_min_se' => '90',
            'force_callerid' => 'no', 'max_audio_streams' => '1', 'max_video_streams' => '1', 'webrtc' => 'no',
            'defaultuser' => '', 'message_context' => '', 'refer_blind_progress' => 'yes', 'vmexten' => '',
            'outbound_auth' => 'no', 'outbound_proxy' => ''];
    }

    public static function userAst(string $number, string $name): array
    {
        return ['cidname' => $name, 'cidnum' => $number, 'device' => $number, 'voicemail' => 'novm',
            'ringtimer' => '0', 'cfringtimer' => '0', 'concurrency_limit' => '0', 'noanswer' => '', 'outboundcid' => '', 'password' => '', 'accountcode' => '',
            'recording/in/external' => 'dontcare', 'recording/out/external' => 'dontcare',
            'recording/in/internal' => 'dontcare', 'recording/out/internal' => 'dontcare',
            'recording/ondemand' => 'disabled', 'recording/priority' => '10', 'screen' => '', 'pinless' => '',
            'answermode' => 'disabled', 'intercom' => 'enabled', 'rvolume' => '', 'cwtone' => 'disabled', 'dialopts' => ''];
    }

    public static function userParameters(array $snapshot, string $number): array
    {
        $row = $snapshot['row'];
        $parameters = ['name' => (string) $row['name'], 'voicemail' => (string) $row['voicemail']];
        $defaults = ['password' => '', 'ringtimer' => '0', 'noanswer' => '', 'recording' => '', 'outboundcid' => '',
            'sipname' => '', 'noanswer_cid' => '', 'busy_cid' => '', 'chanunavail_cid' => '',
            'noanswer_dest' => '', 'busy_dest' => '', 'chanunavail_dest' => ''];
        foreach ($row as $key => $value) {
            if (in_array($key, ['extension','name','voicemail'], true)) { continue; }
            if (!array_key_exists($key, $defaults) || (string) $value !== $defaults[$key]) {
                throw new RuntimeException('USER_UNSUPPORTED_CONFIGURATION');
            }
        }
        foreach (self::userAst($number, $parameters['name']) as $key => $expected) {
            if ((string) ($snapshot['ast'][$key] ?? '') !== $expected) { throw new RuntimeException('USER_AST_STATE_INCOMPLETE_OR_UNSUPPORTED'); }
        }
        if (($snapshot['callwaiting'] ?? '') !== 'ENABLED') { throw new RuntimeException('USER_CALLWAITING_UNSUPPORTED'); }
        return $parameters;
    }
}