<?php
/**
 * Plugin Name: Site Mail Console
 * Description: Встроенная почтовая консоль (Входящие / Отправленные / Корзина) и прямой Brevo API v3 транспорт для wp_mail() (без тяжёлого WP Mail SMTP).
 * Version: 1.2.0
 * Author: US GROUP
 * License: GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: site-mail-console
 */

if (!defined('ABSPATH')) {
    exit;
}

class Site_Mail_Console {
    const TABLE_SUFFIX  = 'site_mail_messages';
    const OPT_SECRET    = 'site_mail_webhook_secret';
    const OPT_BREVO     = 'site_mail_brevo';
    const OPT_MAILRELAY = 'site_mail_mailrelay';
    const NONCE_ACTION  = 'site_mail_console_nonce';

    public static function init(): void {
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        add_action('admin_menu', [__CLASS__, 'register_admin_menu']);
        add_action('wp_ajax_site_mail_action', [__CLASS__, 'handle_ajax']);
        add_action('wpcf7_before_send_mail', [__CLASS__, 'capture_cf7_submission'], 20, 3);
        add_filter('pre_wp_mail', [__CLASS__, 'intercept_wp_mail'], 5, 2);
    }

    public static function get_domain(): string {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        $host = strtolower(trim((string)$host));
        return preg_replace('/^www\./', '', $host);
    }

    public static function get_default_mailbox(): string {
        return 'support@' . self::get_domain();
    }

    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function activate(): void {
        self::ensure_table();
        if (!get_option(self::OPT_SECRET)) {
            update_option(self::OPT_SECRET, bin2hex(random_bytes(32)), false);
        }
    }

    public static function ensure_table(): void {
        static $done = false;
        if ($done) {
            return;
        }
        global $wpdb;
        $table = self::get_table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `folder` VARCHAR(16) NOT NULL DEFAULT 'inbox',
            `mailbox` VARCHAR(191) NOT NULL DEFAULT '',
            `from_email` VARCHAR(255) NOT NULL DEFAULT '',
            `from_name` VARCHAR(255) NOT NULL DEFAULT '',
            `to_email` VARCHAR(255) NOT NULL DEFAULT '',
            `reply_to` VARCHAR(255) NOT NULL DEFAULT '',
            `subject` VARCHAR(500) NOT NULL DEFAULT '(без темы)',
            `body_text` LONGTEXT NULL,
            `body_html` LONGTEXT NULL,
            `message_id` VARCHAR(255) NOT NULL DEFAULT '',
            `in_reply_to` VARCHAR(255) NOT NULL DEFAULT '',
            `raw_headers` TEXT NULL,
            `status` VARCHAR(64) NOT NULL DEFAULT 'received',
            `error_note` TEXT NULL,
            `is_read` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_folder_read_created` (`folder`, `is_read`, `created_at`),
            KEY `idx_mailbox` (`mailbox`),
            KEY `idx_message_id` (`message_id`(191))
        ) {$charset};";
        $wpdb->query($sql);
        $done = true;
    }

    public static function count_unread(): int {
        global $wpdb;
        self::ensure_table();
        $table = self::get_table_name();
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE `folder` = 'inbox' AND `is_read` = 0");
    }

    public static function store_inbound(array $data): int {
        global $wpdb;
        self::ensure_table();
        $table = self::get_table_name();

        $message_id = trim((string)($data['message_id'] ?? ''));
        if ($message_id !== '') {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT `id` FROM `{$table}` WHERE `message_id` = %s AND `folder` = 'inbox' LIMIT 1",
                $message_id
            ));
            if ($existing) {
                return (int)$existing;
            }
        }

        $default_box = self::get_default_mailbox();
        $to_email    = trim((string)($data['to_email'] ?? $default_box));
        $mailbox     = trim((string)($data['mailbox'] ?? $to_email));
        if ($mailbox === '') {
            $mailbox = $default_box;
        }
        $from_email = trim((string)($data['from_email'] ?? ''));
        $from_name  = trim((string)($data['from_name'] ?? ''));
        $reply_to   = trim((string)($data['reply_to'] ?? $from_email));
        $subject    = trim((string)($data['subject'] ?? ''));
        if ($subject === '') {
            $subject = '(без темы)';
        }

        $wpdb->insert(
            $table,
            [
                'folder'      => 'inbox',
                'mailbox'     => mb_substr($mailbox, 0, 191, 'UTF-8'),
                'from_email'  => mb_substr($from_email, 0, 255, 'UTF-8'),
                'from_name'   => mb_substr($from_name, 0, 255, 'UTF-8'),
                'to_email'    => mb_substr($to_email, 0, 255, 'UTF-8'),
                'reply_to'    => mb_substr($reply_to, 0, 255, 'UTF-8'),
                'subject'     => mb_substr($subject, 0, 500, 'UTF-8'),
                'body_text'   => (string)($data['body_text'] ?? ''),
                'body_html'   => (string)($data['body_html'] ?? ''),
                'message_id'  => mb_substr($message_id, 0, 255, 'UTF-8'),
                'in_reply_to' => mb_substr(trim((string)($data['in_reply_to'] ?? '')), 0, 255, 'UTF-8'),
                'raw_headers' => mb_substr((string)($data['raw_headers'] ?? ''), 0, 65000, 'UTF-8'),
                'status'      => mb_substr(trim((string)($data['status'] ?? 'received')), 0, 64, 'UTF-8'),
                'is_read'     => 0,
                'created_at'  => current_time('mysql'),
            ],
            ['%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s']
        );

        return (int)$wpdb->insert_id;
    }

    public static function register_rest_routes(): void {
        register_rest_route('site-mail/v1', '/inbound', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_inbound_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle_inbound_webhook(WP_REST_Request $request) {
        $secret = trim((string)get_option(self::OPT_SECRET, ''));
        if ($secret === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'Secret not configured'], 500);
        }

        $raw = $request->get_body();
        if (!is_string($raw) || $raw === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'Empty body'], 400);
        }

        $sig = strtolower(trim((string)$request->get_header('x-site-mail-sig')));
        $expected = hash_hmac('sha256', $raw, $secret);
        if ($sig === '' || !hash_equals($expected, $sig)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Invalid signature'], 403);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Invalid JSON'], 400);
        }

        $id = self::store_inbound($data);
        return new WP_REST_Response(['ok' => true, 'id' => $id], 200);
    }

    public static function capture_cf7_submission($contact_form, &$abort, $submission): void {
        if ($abort || !$submission || !is_object($submission)) {
            return;
        }
        if (method_exists($submission, 'get_status') && $submission->get_status() === 'spam') {
            return;
        }
        $posted = method_exists($submission, 'get_posted_data') ? $submission->get_posted_data() : [];
        if (!is_array($posted) || empty($posted)) {
            return;
        }

        $from_email = '';
        $from_name  = '';
        $subject    = '';
        $lines      = [];

        foreach ($posted as $k => $v) {
            if (strpos((string)$k, '_wpcf7') === 0) {
                continue;
            }
            $val = is_array($v) ? implode(', ', array_map('strval', $v)) : trim((string)$v);
            if ($val === '') {
                continue;
            }
            $lk = strtolower((string)$k);
            if ($from_email === '' && (strpos($lk, 'email') !== false || filter_var($val, FILTER_VALIDATE_EMAIL))) {
                $from_email = sanitize_email($val);
            } elseif ($from_name === '' && strpos($lk, 'name') !== false) {
                $from_name = sanitize_text_field($val);
            } elseif ($subject === '' && strpos($lk, 'subject') !== false) {
                $subject = sanitize_text_field($val);
            }
            $lines[] = $k . ': ' . $val;
        }

        $form_title = method_exists($contact_form, 'title') ? $contact_form->title() : 'Contact Form';
        if ($subject === '') {
            $subject = '[' . $form_title . '] Новое сообщение с формы';
        }
        if ($from_email === '') {
            $from_email = 'form@' . self::get_domain();
        }

        $text = "Заявка с контактной формы: {$form_title}\n\n" . implode("\n", $lines);
        self::store_inbound([
            'mailbox'    => self::get_default_mailbox(),
            'to_email'   => self::get_default_mailbox(),
            'from_email' => $from_email,
            'from_name'  => $from_name,
            'reply_to'   => $from_email,
            'subject'    => $subject,
            'body_text'  => $text,
            'body_html'  => nl2br(esc_html($text)),
            'status'     => 'contact_form',
        ]);
    }

    public static function get_mailrelay_config(): ?array {
        $cfg = get_option(self::OPT_MAILRELAY, []);
        if (!is_array($cfg)) {
            return null;
        }
        $host = strtolower(trim((string)($cfg['host'] ?? '')));
        $key  = trim((string)($cfg['api_key'] ?? ''));
        if ($host === '' || $key === '') {
            return null;
        }
        return [
            'host'         => $host,
            'api_key'      => $key,
            'default_from' => strtolower(trim((string)($cfg['default_from'] ?? self::get_default_mailbox()))),
            'default_name' => trim((string)($cfg['default_name'] ?? html_entity_decode((string)get_bloginfo('name'), ENT_QUOTES, 'UTF-8'))),
        ];
    }

    public static function send_via_mailrelay(
        array $mr,
        string $from_email,
        string $from_name,
        array $to_list,
        string $subject,
        string $html_part,
        string $text_part = '',
        array $custom_headers = []
    ): array {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message_id' => '', 'error' => 'PHP cURL unavailable'];
        }
        $clean_to = [];
        foreach ($to_list as $item) {
            $email = is_array($item) ? sanitize_email((string)($item['email'] ?? '')) : sanitize_email((string)$item);
            if (is_email($email)) {
                $entry = ['email' => $email];
                if (is_array($item) && !empty($item['name'])) {
                    $entry['name'] = trim((string)$item['name']);
                }
                $clean_to[] = $entry;
            }
        }
        if (empty($clean_to)) {
            return ['ok' => false, 'message_id' => '', 'error' => 'Нет валидных получателей'];
        }

        // Mailrelay rejects reserved headers in the custom `headers` map
        $reserved = [
            'authentication-results', 'bcc', 'cc', 'dkim-signature', 'date',
            'delivered-to', 'domainkey-status', 'from', 'list-id', 'list-unsubscribe',
            'list-unsubscribe-post', 'received', 'received-spf', 'return-path',
            'sender', 'subject', 'to', 'user-agent', 'content-type', 'mime-version',
            'content-transfer-encoding', 'x-mailer',
        ];
        $safe_headers = [];
        foreach ($custom_headers as $hk => $hv) {
            $k = trim((string)$hk);
            $v = trim(str_replace(["\r", "\n"], '', (string)$hv));
            if ($k === '' || $v === '' || in_array(strtolower($k), $reserved, true)) {
                continue;
            }
            $safe_headers[$k] = $v;
        }

        $clean_subject = trim(str_replace(["\r", "\n"], '', $subject));
        if ($clean_subject === '') {
            $clean_subject = get_bloginfo('name');
        }
        if ($text_part === '' && $html_part !== '') {
            $text_part = trim(wp_strip_all_tags(html_entity_decode($html_part, ENT_QUOTES, 'UTF-8')));
        }
        if ($html_part === '' && $text_part !== '') {
            $html_part = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:15px;line-height:1.6;">'
                . nl2br(esc_html($text_part)) . '</div>';
        }

        $payload = [
            'from'      => ['email' => $from_email, 'name' => $from_name ?: self::get_domain()],
            'to'        => array_slice($clean_to, 0, 100),
            'subject'   => $clean_subject,
            'html_part' => $html_part,
            'text_part' => $text_part,
        ];
        if (!empty($safe_headers)) {
            $payload['headers'] = $safe_headers;
        }

        $url = 'https://' . $mr['host'] . '/api/v1/send_emails';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 18,
            CURLOPT_IPRESOLVE      => defined('CURL_IPRESOLVE_V4') ? CURL_IPRESOLVE_V4 : 1,
            CURLOPT_HTTPHEADER     => [
                'X-AUTH-TOKEN: ' . $mr['api_key'],
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => wp_json_encode($payload),
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        $decoded = is_string($resp) ? json_decode($resp, true) : null;
        if ($code >= 200 && $code < 300) {
            $mid = '';
            if (is_array($decoded)) {
                if (!empty($decoded['data']['message_id'])) {
                    $mid = (string)$decoded['data']['message_id'];
                } elseif (!empty($decoded['message_id'])) {
                    $mid = (string)$decoded['message_id'];
                } elseif (!empty($decoded['id'])) {
                    $mid = 'mailrelay-' . $decoded['id'];
                } elseif (isset($decoded[0]['id'])) {
                    $mid = 'mailrelay-' . $decoded[0]['id'];
                }
            }
            return ['ok' => true, 'message_id' => $mid, 'error' => ''];
        }

        $err = 'Mailrelay HTTP ' . $code;
        if (is_array($decoded)) {
            if (!empty($decoded['errors'])) {
                $err .= ': ' . wp_json_encode($decoded['errors'], JSON_UNESCAPED_UNICODE);
            } elseif (!empty($decoded['error'])) {
                $err .= ': ' . $decoded['error'];
            }
        } elseif ($cerr !== '') {
            $err .= ': ' . $cerr;
        } elseif (is_string($resp) && $resp !== '') {
            $err .= ': ' . mb_substr($resp, 0, 220, 'UTF-8');
        }
        return ['ok' => false, 'message_id' => '', 'error' => $err];
    }

    public static function get_brevo_config(): ?array {
        $cfg = get_option(self::OPT_BREVO, []);
        if (is_array($cfg) && !empty($cfg['api_key'])) {
            $domain = self::get_domain();
            return [
                'api_key'      => trim((string)$cfg['api_key']),
                'default_from' => strtolower(trim((string)($cfg['default_from'] ?? ('noreply@' . $domain)))),
                'default_name' => trim((string)($cfg['default_name'] ?? html_entity_decode((string)get_bloginfo('name'), ENT_QUOTES, 'UTF-8'))),
                'reply_to'     => strtolower(trim((string)($cfg['reply_to'] ?? ('support@' . $domain)))),
                'standalone'   => true,
            ];
        }
        // Fallback to legacy wp_mail_smtp key if site hasn't migrated to standalone site_mail_brevo yet
        $smtp_opt = get_option('wp_mail_smtp', []);
        $legacy   = trim((string)($smtp_opt['sendinblue']['api_key'] ?? ''));
        if ($legacy !== '') {
            $domain = self::get_domain();
            return [
                'api_key'      => $legacy,
                'default_from' => strtolower(trim((string)($smtp_opt['mail']['from_email'] ?? ('noreply@' . $domain)))),
                'default_name' => trim((string)($smtp_opt['mail']['from_name'] ?? html_entity_decode((string)get_bloginfo('name'), ENT_QUOTES, 'UTF-8'))),
                'reply_to'     => 'support@' . $domain,
                'standalone'   => false,
            ];
        }
        return null;
    }

    public static function send_via_brevo(
        string $api_key,
        string $from_email,
        string $from_name,
        array $to_list,
        string $subject,
        string $html_part,
        string $text_part = '',
        string $reply_to = '',
        array $custom_headers = []
    ): array {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message_id' => '', 'error' => 'PHP cURL unavailable'];
        }
        $clean_to = [];
        foreach ($to_list as $item) {
            $email = is_array($item) ? sanitize_email((string)($item['email'] ?? '')) : sanitize_email((string)$item);
            if (is_email($email)) {
                $entry = ['email' => $email];
                if (is_array($item) && !empty($item['name'])) {
                    $entry['name'] = trim((string)$item['name']);
                }
                $clean_to[] = $entry;
            }
        }
        if (empty($clean_to)) {
            return ['ok' => false, 'message_id' => '', 'error' => 'Нет валидных получателей'];
        }

        $clean_subject = trim(str_replace(["\r", "\n"], '', $subject));
        if ($clean_subject === '') {
            $clean_subject = get_bloginfo('name');
        }
        if ($text_part === '' && $html_part !== '') {
            $text_part = trim(wp_strip_all_tags(html_entity_decode($html_part, ENT_QUOTES, 'UTF-8')));
        }
        if ($html_part === '' && $text_part !== '') {
            $html_part = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:15px;line-height:1.6;color:#111827;">'
                . nl2br(esc_html($text_part)) . '</div>';
        }

        $payload = [
            'sender'      => ['name' => ($from_name ?: self::get_domain()), 'email' => $from_email],
            'to'          => $clean_to,
            'subject'     => $clean_subject,
            'htmlContent' => $html_part,
            'textContent' => $text_part,
        ];
        if ($reply_to !== '' && is_email($reply_to)) {
            $payload['replyTo'] = ['email' => $reply_to];
        }
        $safe_hdrs = [];
        foreach ($custom_headers as $hk => $hv) {
            $k = trim((string)$hk);
            $v = trim(str_replace(["\r", "\n"], '', (string)$hv));
            if ($k !== '' && $v !== '') {
                $safe_hdrs[$k] = $v;
            }
        }
        if (!empty($safe_hdrs)) {
            $payload['headers'] = $safe_hdrs;
        }

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 18,
            CURLOPT_IPRESOLVE      => defined('CURL_IPRESOLVE_V4') ? CURL_IPRESOLVE_V4 : 1,
            CURLOPT_HTTPHEADER     => [
                'api-key: ' . $api_key,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => wp_json_encode($payload),
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        $decoded = is_string($resp) ? json_decode($resp, true) : null;
        if ($code >= 200 && $code < 300) {
            return ['ok' => true, 'message_id' => (string)($decoded['messageId'] ?? ''), 'error' => ''];
        }
        $err = 'Brevo HTTP ' . $code;
        if (is_array($decoded) && !empty($decoded['message'])) {
            $err .= ': ' . $decoded['message'];
        } elseif ($cerr !== '') {
            $err .= ': ' . $cerr;
        } elseif (is_string($resp) && $resp !== '') {
            $err .= ': ' . mb_substr($resp, 0, 220, 'UTF-8');
        }
        return ['ok' => false, 'message_id' => '', 'error' => $err];
    }

    public static function intercept_wp_mail($return, $atts) {
        if ($return !== null) {
            return $return;
        }
        $mr = self::get_mailrelay_config();
        $br = self::get_brevo_config();
        // Intercept globally whenever standalone site_mail_brevo OR mailrelay is active (no WP Mail SMTP needed!)
        $use_brevo_standalone = ($br !== null && !empty($br['standalone']));
        if (!$mr && !$use_brevo_standalone) {
            return null;
        }

        $to_raw  = $atts['to'] ?? '';
        $subject = (string)($atts['subject'] ?? '');
        $message = (string)($atts['message'] ?? '');
        $headers = $atts['headers'] ?? [];

        $to_emails = [];
        $items = is_array($to_raw) ? $to_raw : explode(',', (string)$to_raw);
        foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item === '') continue;
            if (preg_match('/^(.*?)<([^<>@\s]+@[^<>@\s]+)>/', $item, $m)) {
                $to_emails[] = ['email' => sanitize_email($m[2]), 'name' => trim($m[1], " \"'")];
            } elseif (is_email($item)) {
                $to_emails[] = ['email' => sanitize_email($item)];
            }
        }
        if (empty($to_emails)) {
            return false;
        }

        $hdr_lines = is_array($headers) ? $headers : explode("\n", str_replace("\r\n", "\n", (string)$headers));
        $custom_headers = [];
        $reply_to = '';
        $is_html = false;
        foreach ($hdr_lines as $hl) {
            $hl = trim((string)$hl);
            if ($hl === '' || strpos($hl, ':') === false) continue;
            [$hk, $hv] = array_map('trim', explode(':', $hl, 2));
            $lhk = strtolower($hk);
            if ($lhk === 'content-type' && stripos($hv, 'text/html') !== false) {
                $is_html = true;
            } elseif ($lhk === 'reply-to') {
                if (preg_match('/<([^<>@\s]+@[^<>@\s]+)>/', $hv, $rm)) {
                    $reply_to = sanitize_email($rm[1]);
                } elseif (is_email($hv)) {
                    $reply_to = sanitize_email($hv);
                }
            } elseif (in_array($lhk, ['in-reply-to', 'references', 'list-unsubscribe', 'list-unsubscribe-post'], true)) {
                $custom_headers[$hk] = $hv;
            }
        }
        if (!$is_html && preg_match('/<(?:html|body|p|div|table|br|a\s|h[1-6])\b/i', $message)) {
            $is_html = true;
        }
        $html_part = $is_html ? $message : '';
        $text_part = $is_html ? '' : $message;

        if ($use_brevo_standalone) {
            $final_reply = $reply_to !== '' ? $reply_to : $br['reply_to'];
            $res = self::send_via_brevo(
                $br['api_key'],
                $br['default_from'],
                $br['default_name'] ?: self::get_domain(),
                $to_emails,
                $subject,
                $html_part,
                $text_part,
                $final_reply,
                $custom_headers
            );
            if (!$res['ok']) {
                error_log('[Site_Mail_Console Brevo wp_mail error] ' . $res['error']);
            }
            return (bool)$res['ok'];
        }

        if ($reply_to !== '') {
            $custom_headers['Reply-To'] = $reply_to;
        } else {
            $custom_headers['Reply-To'] = self::get_default_mailbox();
        }
        $res = self::send_via_mailrelay(
            $mr,
            $mr['default_from'],
            $mr['default_name'] ?: self::get_domain(),
            $to_emails,
            $subject,
            $html_part,
            $text_part,
            $custom_headers
        );
        if (!$res['ok']) {
            error_log('[Site_Mail_Console Mailrelay wp_mail error] ' . $res['error']);
        }
        return (bool)$res['ok'];
    }

    public static function send_and_log(string $to_email, string $subject, string $body_text, string $from_email = '', string $in_reply_to = ''): array {
        global $wpdb;
        self::ensure_table();
        $domain = self::get_domain();
        $allowed = [
            'support@' . $domain,
            'info@' . $domain,
            'noreply@' . $domain,
        ];
        $from_email = strtolower(trim($from_email));
        if (!in_array($from_email, $allowed, true)) {
            $from_email = 'support@' . $domain;
        }

        $to_email = sanitize_email($to_email);
        if (!is_email($to_email)) {
            return ['ok' => false, 'error' => 'Некорректный email получателя'];
        }
        $clean_subject = trim(str_replace(["\r", "\n"], '', $subject));
        if ($clean_subject === '') {
            $clean_subject = 'Re: ' . get_bloginfo('name');
        }
        $body_text = trim($body_text);
        if ($body_text === '') {
            return ['ok' => false, 'error' => 'Текст письма не может быть пустым'];
        }

        $site_name = html_entity_decode((string)get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        if ($site_name === '') {
            $site_name = strtoupper($domain);
        }
        $from_name = $site_name . ' Support';

        $body_html = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:15px;line-height:1.6;color:#111827;">'
            . nl2br(esc_html($body_text))
            . '<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0;">'
            . '<p style="font-size:12px;color:#6b7280;">' . esc_html($site_name) . ' &middot; <a href="' . esc_url(home_url('/')) . '" style="color:#2563eb;">' . esc_html($domain) . '</a></p>'
            . '</div>';

        $ok = false;
        $sent_msg_id = '';
        $err_msg = '';

        $hdrs = [];
        if ($in_reply_to !== '') {
            $clean_ref = trim(str_replace(["\r", "\n"], '', $in_reply_to));
            $hdrs['In-Reply-To'] = $clean_ref;
            $hdrs['References']  = $clean_ref;
        }

        $br = self::get_brevo_config();
        $mr = self::get_mailrelay_config();

        if ($br !== null) {
            $b_res = self::send_via_brevo(
                $br['api_key'],
                $from_email,
                $from_name,
                [$to_email],
                $clean_subject,
                $body_html,
                $body_text,
                self::get_default_mailbox(),
                $hdrs
            );
            $ok          = $b_res['ok'];
            $sent_msg_id = $b_res['message_id'];
            $err_msg     = $b_res['error'];
        } elseif ($mr !== null) {
            $hdrs['Reply-To'] = self::get_default_mailbox();
            $mr_res = self::send_via_mailrelay($mr, $from_email, $from_name, [$to_email], $clean_subject, $body_html, $body_text, $hdrs);
            $ok          = $mr_res['ok'];
            $sent_msg_id = $mr_res['message_id'];
            $err_msg     = $mr_res['error'];
        } else {
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $from_name . ' <' . $from_email . '>',
                'Reply-To: ' . self::get_default_mailbox(),
            ];
            if ($in_reply_to !== '') {
                $headers[] = 'In-Reply-To: ' . $hdrs['In-Reply-To'];
                $headers[] = 'References: ' . $hdrs['References'];
            }
            $ok = (bool)wp_mail($to_email, $clean_subject, $body_html, $headers);
            if (!$ok) {
                $err_msg = 'wp_mail() вернул false';
            }
        }

        $table = self::get_table_name();
        $wpdb->insert(
            $table,
            [
                'folder'      => 'sent',
                'mailbox'     => $from_email,
                'from_email'  => $from_email,
                'from_name'   => $from_name,
                'to_email'    => $to_email,
                'reply_to'    => $from_email,
                'subject'     => mb_substr($clean_subject, 0, 500, 'UTF-8'),
                'body_text'   => $body_text,
                'body_html'   => $body_html,
                'message_id'  => $sent_msg_id,
                'in_reply_to' => $in_reply_to,
                'status'      => $ok ? 'sent' : 'failed',
                'error_note'  => $err_msg,
                'is_read'     => 1,
                'created_at'  => current_time('mysql'),
            ],
            ['%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s']
        );

        return [
            'ok'         => $ok,
            'id'         => (int)$wpdb->insert_id,
            'message_id' => $sent_msg_id,
            'error'      => $err_msg,
        ];
    }

    public static function register_admin_menu(): void {
        $unread = self::count_unread();
        $badge = $unread > 0 ? ' <span class="awaiting-mod count-' . $unread . '"><span class="pending-count">' . $unread . '</span></span>' : '';
        add_menu_page(
            'Почта — ' . self::get_default_mailbox(),
            'Почта' . $badge,
            'manage_options',
            'site-mail-console',
            [__CLASS__, 'render_admin_page'],
            'dashicons-email-alt',
            26
        );
    }

    public static function handle_ajax(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'Forbidden'], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        global $wpdb;
        self::ensure_table();
        $table = self::get_table_name();
        $cmd = sanitize_key($_REQUEST['cmd'] ?? 'list');

        if ($cmd === 'list') {
            $folder = sanitize_key($_REQUEST['folder'] ?? 'inbox');
            if (!in_array($folder, ['inbox', 'sent', 'trash'], true)) {
                $folder = 'inbox';
            }
            $q = trim((string)wp_unslash($_REQUEST['q'] ?? ''));

            if ($q !== '') {
                $like = '%' . $wpdb->esc_like($q) . '%';
                $items = $wpdb->get_results($wpdb->prepare(
                    "SELECT `id`, `folder`, `mailbox`, `from_email`, `from_name`, `to_email`, `reply_to`, `subject`, LEFT(COALESCE(`body_text`, ''), 180) AS `snippet`, `status`, `error_note`, `is_read`, `created_at`
                     FROM `{$table}`
                     WHERE `folder` = %s AND (`subject` LIKE %s OR `from_email` LIKE %s OR `from_name` LIKE %s OR `to_email` LIKE %s OR `body_text` LIKE %s)
                     ORDER BY `created_at` DESC, `id` DESC LIMIT 80",
                    $folder, $like, $like, $like, $like, $like
                ), ARRAY_A);
            } else {
                $items = $wpdb->get_results($wpdb->prepare(
                    "SELECT `id`, `folder`, `mailbox`, `from_email`, `from_name`, `to_email`, `reply_to`, `subject`, LEFT(COALESCE(`body_text`, ''), 180) AS `snippet`, `status`, `error_note`, `is_read`, `created_at`
                     FROM `{$table}`
                     WHERE `folder` = %s
                     ORDER BY `created_at` DESC, `id` DESC LIMIT 80",
                    $folder
                ), ARRAY_A);
            }

            $rows = $wpdb->get_results("SELECT `folder`, COUNT(*) AS total, SUM(CASE WHEN `is_read` = 0 THEN 1 ELSE 0 END) AS unread FROM `{$table}` GROUP BY `folder`", ARRAY_A);
            $counts = ['inbox' => 0, 'inbox_unread' => 0, 'sent' => 0, 'trash' => 0];
            foreach ($rows as $r) {
                $f = $r['folder'];
                if (isset($counts[$f])) {
                    $counts[$f] = (int)$r['total'];
                }
                if ($f === 'inbox') {
                    $counts['inbox_unread'] = (int)$r['unread'];
                }
            }

            wp_send_json_success(['items' => $items, 'counts' => $counts]);
        }

        if ($cmd === 'get') {
            $id = (int)($_REQUEST['id'] ?? 0);
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE `id` = %d LIMIT 1", $id), ARRAY_A);
            if (!$row) {
                wp_send_json_error(['error' => 'Письмо не найдено'], 404);
            }
            if ((int)$row['is_read'] === 0 && $row['folder'] === 'inbox') {
                $wpdb->update($table, ['is_read' => 1], ['id' => $id], ['%d'], ['%d']);
                $row['is_read'] = 1;
            }
            wp_send_json_success(['message' => $row, 'unread' => self::count_unread()]);
        }

        if ($cmd === 'send') {
            $res = self::send_and_log(
                (string)wp_unslash($_POST['to_email'] ?? ''),
                (string)wp_unslash($_POST['subject'] ?? ''),
                (string)wp_unslash($_POST['body_text'] ?? ''),
                (string)wp_unslash($_POST['from_email'] ?? ''),
                (string)wp_unslash($_POST['in_reply_to'] ?? '')
            );
            wp_send_json_success($res);
        }

        if ($cmd === 'mark_read') {
            $id = (int)($_POST['id'] ?? 0);
            $val = !empty($_POST['is_read']) ? 1 : 0;
            $wpdb->update($table, ['is_read' => $val], ['id' => $id], ['%d'], ['%d']);
            wp_send_json_success(['unread' => self::count_unread()]);
        }

        if ($cmd === 'move') {
            $id = (int)($_POST['id'] ?? 0);
            $folder = sanitize_key($_POST['folder'] ?? 'trash');
            if (!in_array($folder, ['inbox', 'sent', 'trash'], true)) {
                wp_send_json_error(['error' => 'Invalid folder'], 400);
            }
            $wpdb->update($table, ['folder' => $folder], ['id' => $id], ['%s'], ['%d']);
            wp_send_json_success(['unread' => self::count_unread()]);
        }

        if ($cmd === 'delete_forever') {
            $id = (int)($_POST['id'] ?? 0);
            $wpdb->delete($table, ['id' => $id], ['%d']);
            wp_send_json_success(['unread' => self::count_unread()]);
        }

        wp_send_json_error(['error' => 'Unknown cmd'], 400);
    }

    public static function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $domain  = self::get_domain();
        $mailbox = self::get_default_mailbox();
        $nonce   = wp_create_nonce(self::NONCE_ACTION);
        $ajax    = admin_url('admin-ajax.php');
        ?>
        <div class="wrap smc-wrap">
            <style>
                .smc-wrap { max-width: 1400px; }
                .smc-topbar {
                    display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px;
                    background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:14px 18px; margin-bottom:14px;
                }
                .smc-title-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
                .smc-badge { background:#eef4ff; color:#1d4ed8; border:1px solid #bfdbfe; padding:4px 10px; border-radius:999px; font-weight:600; font-size:13px; }
                .smc-badge-ok { background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; padding:3px 9px; border-radius:999px; font-size:12px; }
                .smc-grid { display:grid; grid-template-columns:360px 1fr; gap:14px; min-height:600px; }
                @media (max-width:960px) { .smc-grid { grid-template-columns:1fr; } }
                .smc-card { background:#fff; border:1px solid #dcdcde; border-radius:8px; display:flex; flex-direction:column; overflow:hidden; }
                .smc-folders { display:flex; gap:6px; padding:8px; border-bottom:1px solid #e5e7eb; background:#f8fafc; }
                .smc-fbtn { flex:1; border:1px solid transparent; background:transparent; padding:7px 8px; border-radius:6px; cursor:pointer; font-weight:600; font-size:12px; color:#475569; }
                .smc-fbtn.active { background:#e0e7ff; border-color:#a5b4fc; color:#1e40af; }
                .smc-search { padding:8px; border-bottom:1px solid #e5e7eb; }
                .smc-search input { width:100%; }
                .smc-list { overflow-y:auto; max-height:600px; flex:1; }
                .smc-item { padding:12px 14px; border-bottom:1px solid #f1f5f9; cursor:pointer; }
                .smc-item:hover { background:#f8fafc; }
                .smc-item.active { background:#eff6ff; border-left:3px solid #2563eb; }
                .smc-item.unread .smc-item-subj, .smc-item.unread .smc-item-from { font-weight:700; color:#0f172a; }
                .smc-item-top { display:flex; justify-content:space-between; gap:8px; font-size:12px; color:#64748b; }
                .smc-item-from { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
                .smc-item-subj { margin-top:4px; font-size:13.5px; color:#1e293b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
                .smc-item-snip { margin-top:3px; font-size:12px; color:#64748b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
                .smc-reader { padding:18px; display:flex; flex-direction:column; gap:14px; flex:1; }
                .smc-empty { margin:auto; text-align:center; color:#64748b; padding:50px 20px; }
                .smc-meta { border-bottom:1px solid #e5e7eb; padding-bottom:12px; }
                .smc-meta h2 { margin:0 0 8px 0; font-size:18px; }
                .smc-meta-line { font-size:13px; color:#475569; margin:3px 0; }
                .smc-frame { width:100%; min-height:280px; border:1px solid #e5e7eb; border-radius:6px; background:#fff; }
                .smc-pre { white-space:pre-wrap; background:#f8fafc; border:1px solid #e5e7eb; border-radius:6px; padding:14px; font-size:14px; line-height:1.6; max-height:360px; overflow-y:auto; }
                .smc-reply { border-top:1px solid #e5e7eb; padding-top:14px; display:flex; flex-direction:column; gap:10px; }
                .smc-notice { padding:9px 12px; border-radius:6px; font-size:13px; display:none; }
                .smc-notice.ok { display:block; background:#ecfdf5; border:1px solid #10b981; color:#065f46; }
                .smc-notice.err { display:block; background:#fef2f2; border:1px solid #ef4444; color:#991b1b; }
                .smc-modal-bg { position:fixed; inset:0; background:rgba(15,23,42,.6); z-index:100000; display:none; align-items:center; justify-content:center; padding:16px; }
                .smc-modal { width:100%; max-width:600px; background:#fff; border-radius:10px; padding:18px; display:flex; flex-direction:column; gap:10px; box-shadow:0 20px 50px rgba(0,0,0,.25); }
            </style>

            <div class="smc-topbar">
                <div class="smc-title-row">
                    <h1 style="margin:0;padding:0;font-size:20px;">📧 Почта сайта</h1>
                    <span class="smc-badge"><?php echo esc_html($mailbox); ?></span>
                    <span class="smc-badge-ok">Входящие: Cloudflare MX (*@<?php echo esc_html($domain); ?>)</span>
                    <?php $br_active = self::get_brevo_config(); ?>
                    <span class="smc-badge-ok"><?php echo ($br_active && !empty($br_active['standalone'])) ? 'Исходящие: Brevo API (' . esc_html($br_active['default_from']) . ' • 300/день)' : 'Исходящие: Brevo API'; ?></span>
                </div>
                <div style="display:flex;gap:8px;">
                    <button type="button" class="button button-primary" onclick="smcOpenCompose()">✉️ Написать письмо</button>
                    <button type="button" class="button" onclick="smcLoadList()">🔄 Обновить</button>
                </div>
            </div>

            <div class="smc-grid">
                <div class="smc-card">
                    <div class="smc-folders">
                        <button type="button" class="smc-fbtn active" data-folder="inbox" onclick="smcSetFolder('inbox')">📥 Входящие <span id="smc-cnt-inbox"></span></button>
                        <button type="button" class="smc-fbtn" data-folder="sent" onclick="smcSetFolder('sent')">📤 Отправленные <span id="smc-cnt-sent"></span></button>
                        <button type="button" class="smc-fbtn" data-folder="trash" onclick="smcSetFolder('trash')">🗑 Корзина <span id="smc-cnt-trash"></span></button>
                    </div>
                    <div class="smc-search">
                        <input type="search" id="smc-q" placeholder="Поиск по теме, адресу или тексту..." oninput="smcDebouncedSearch()">
                    </div>
                    <div class="smc-list" id="smc-list">
                        <div style="padding:24px;text-align:center;color:#64748b;">Загрузка...</div>
                    </div>
                </div>

                <div class="smc-card">
                    <div class="smc-reader" id="smc-reader">
                        <div class="smc-empty">
                            <div style="font-size:38px;margin-bottom:8px;">📬</div>
                            <div>Выберите письмо слева, чтобы прочитать и ответить прямо от <strong><?php echo esc_html($mailbox); ?></strong>.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="smc-modal-bg" id="smc-compose-modal">
                <div class="smc-modal">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <h2 style="margin:0;font-size:17px;">✉️ Новое письмо</h2>
                        <button type="button" class="button" onclick="smcCloseCompose()">✕</button>
                    </div>
                    <div id="smc-c-status" class="smc-notice"></div>
                    <label><strong>От кого</strong></label>
                    <select id="smc-c-from">
                        <option value="support@<?php echo esc_attr($domain); ?>">support@<?php echo esc_html($domain); ?></option>
                        <option value="info@<?php echo esc_attr($domain); ?>">info@<?php echo esc_html($domain); ?></option>
                        <option value="noreply@<?php echo esc_attr($domain); ?>">noreply@<?php echo esc_html($domain); ?></option>
                    </select>
                    <label><strong>Кому (Email)</strong></label>
                    <input type="email" id="smc-c-to" placeholder="recipient@example.com">
                    <label><strong>Тема</strong></label>
                    <input type="text" id="smc-c-subject" placeholder="Тема письма">
                    <label><strong>Текст сообщения</strong></label>
                    <textarea id="smc-c-body" rows="7" placeholder="Введите сообщение..."></textarea>
                    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:4px;">
                        <button type="button" class="button" onclick="smcCloseCompose()">Отмена</button>
                        <button type="button" class="button button-primary" id="smc-c-btn" onclick="smcSendCompose()">📤 Отправить</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function(){
            const AJAX = <?php echo wp_json_encode($ajax); ?>;
            const NONCE = <?php echo wp_json_encode($nonce); ?>;
            const DOMAIN = <?php echo wp_json_encode($domain); ?>;
            let folder = 'inbox';
            let currentId = null;
            let currentMsg = null;
            let sTimer = null;

            function esc(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

            async function api(cmd, payload = {}) {
                const fd = new FormData();
                fd.append('action', 'site_mail_action');
                fd.append('nonce', NONCE);
                fd.append('cmd', cmd);
                Object.keys(payload).forEach(k => fd.append(k, payload[k]));
                const r = await fetch(AJAX, {method:'POST', credentials:'same-origin', body: fd});
                const j = await r.json();
                if (!j.success) throw new Error((j.data && j.data.error) || 'Ошибка сервера');
                return j.data;
            }

            window.smcSetFolder = function(f) {
                folder = f; currentId = null;
                document.querySelectorAll('.smc-fbtn').forEach(b => b.classList.toggle('active', b.dataset.folder === f));
                smcLoadList();
            };

            window.smcDebouncedSearch = function() {
                clearTimeout(sTimer);
                sTimer = setTimeout(smcLoadList, 280);
            };

            window.smcLoadList = async function() {
                const listEl = document.getElementById('smc-list');
                try {
                    const q = document.getElementById('smc-q').value || '';
                    const data = await api('list', {folder, q});
                    const c = data.counts || {};
                    document.getElementById('smc-cnt-inbox').textContent = c.inbox_unread > 0 ? `(${c.inbox_unread}/${c.inbox})` : (c.inbox ? `(${c.inbox})` : '');
                    document.getElementById('smc-cnt-sent').textContent = c.sent ? `(${c.sent})` : '';
                    document.getElementById('smc-cnt-trash').textContent = c.trash ? `(${c.trash})` : '';

                    if (!data.items || !data.items.length) {
                        listEl.innerHTML = '<div style="padding:28px;text-align:center;color:#64748b;">В этой папке пока нет писем</div>';
                        return;
                    }
                    listEl.innerHTML = data.items.map(it => {
                        const unread = (it.folder === 'inbox' && Number(it.is_read) === 0);
                        const who = it.folder === 'sent' ? ('Кому: ' + it.to_email) : (it.from_name ? `${it.from_name} <${it.from_email}>` : it.from_email);
                        const fail = it.status === 'failed' ? ' <span style="color:#dc2626;">[ошибка]</span>' : '';
                        return `<div class="smc-item ${unread ? 'unread' : ''} ${Number(it.id) === Number(currentId) ? 'active' : ''}" onclick="smcOpenMsg(${it.id})">
                            <div class="smc-item-top">
                                <span class="smc-item-from">${unread ? '● ' : ''}${esc(who)}</span>
                                <span>${esc((it.created_at || '').slice(0,16))}</span>
                            </div>
                            <div class="smc-item-subj">${esc(it.subject)}${fail}</div>
                            <div class="smc-item-snip">${esc(it.snippet || '')}</div>
                        </div>`;
                    }).join('');
                } catch (e) {
                    listEl.innerHTML = `<div style="padding:20px;color:#dc2626;">${esc(e.message)}</div>`;
                }
            };

            window.smcOpenMsg = async function(id) {
                currentId = id;
                const reader = document.getElementById('smc-reader');
                reader.innerHTML = '<div class="smc-empty">Загрузка письма...</div>';
                try {
                    const data = await api('get', {id});
                    const m = data.message;
                    currentMsg = m;
                    smcLoadList();

                    const replyTo = m.folder === 'sent' ? m.to_email : (m.reply_to || m.from_email);
                    const hasHtml = Boolean(m.body_html && m.body_html.trim().length > 0);
                    const errBanner = m.error_note ? `<div class="smc-notice err" style="display:block;">Статус доставки: ${esc(m.error_note)}</div>` : '';

                    reader.innerHTML = `
                        <div class="smc-meta">
                            <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                                <h2>${esc(m.subject)}</h2>
                                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                    ${m.folder === 'inbox' ? `<button type="button" class="button" onclick="smcToggleRead(${m.id}, ${Number(m.is_read) ? 0 : 1})">${Number(m.is_read) ? '📩 Непрочитано' : '✅ Прочитано'}</button>` : ''}
                                    ${m.folder !== 'trash'
                                        ? `<button type="button" class="button" onclick="smcMove(${m.id}, 'trash')">🗑 В корзину</button>`
                                        : `<button type="button" class="button" onclick="smcMove(${m.id}, 'inbox')">↩ Восстановить</button>
                                           <button type="button" class="button button-link-delete" onclick="smcDeleteForever(${m.id})">✕ Удалить навсегда</button>`}
                                </div>
                            </div>
                            <div class="smc-meta-line"><strong>От:</strong> ${esc(m.from_name ? `${m.from_name} <${m.from_email}>` : m.from_email)}</div>
                            <div class="smc-meta-line"><strong>Кому:</strong> ${esc(m.to_email)} &nbsp;|&nbsp; <strong>Ящик:</strong> ${esc(m.mailbox)} &nbsp;|&nbsp; <strong>Дата:</strong> ${esc(m.created_at)}</div>
                        </div>
                        ${errBanner}
                        <div>
                            ${hasHtml
                                ? `<div style="margin-bottom:6px;display:flex;gap:6px;">
                                     <button type="button" class="button button-small" onclick="smcBodyMode('html')">HTML</button>
                                     <button type="button" class="button button-small" onclick="smcBodyMode('text')">Текст</button>
                                   </div>
                                   <iframe id="smc-frame" class="smc-frame" sandbox="" referrerpolicy="no-referrer"></iframe>
                                   <div id="smc-pre" class="smc-pre" style="display:none;">${esc(m.body_text || '(нет текстовой версии)')}</div>`
                                : `<div class="smc-pre">${esc(m.body_text || '(пустое письмо)')}</div>`}
                        </div>
                        <div class="smc-reply">
                            <strong>↩ Ответить на ${esc(replyTo)}</strong>
                            <div id="smc-r-status" class="smc-notice"></div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                                <select id="smc-r-from">
                                    <option value="support@${esc(DOMAIN)}">От: support@${esc(DOMAIN)}</option>
                                    <option value="info@${esc(DOMAIN)}">От: info@${esc(DOMAIN)}</option>
                                    <option value="noreply@${esc(DOMAIN)}">От: noreply@${esc(DOMAIN)}</option>
                                </select>
                                <input type="text" id="smc-r-subject" value="${esc(/^Re:/i.test(m.subject) ? m.subject : 'Re: ' + m.subject)}">
                            </div>
                            <textarea id="smc-r-body" rows="5" placeholder="Введите ответ..."></textarea>
                            <div style="display:flex;justify-content:flex-end;">
                                <button type="button" class="button button-primary" id="smc-r-btn" onclick="smcSendReply()">📤 Отправить ответ</button>
                            </div>
                        </div>
                    `;
                    if (hasHtml) {
                        const fr = document.getElementById('smc-frame');
                        if (fr) fr.srcdoc = m.body_html;
                    }
                } catch (e) {
                    reader.innerHTML = `<div class="smc-empty" style="color:#dc2626;">${esc(e.message)}</div>`;
                }
            };

            window.smcBodyMode = function(mode) {
                const f = document.getElementById('smc-frame'), p = document.getElementById('smc-pre');
                if (!f || !p) return;
                f.style.display = mode === 'html' ? 'block' : 'none';
                p.style.display = mode === 'text' ? 'block' : 'none';
            };

            window.smcToggleRead = async function(id, val) { await api('mark_read', {id, is_read: val}); smcLoadList(); };
            window.smcMove = async function(id, target) {
                await api('move', {id, folder: target});
                currentId = null;
                document.getElementById('smc-reader').innerHTML = '<div class="smc-empty">Письмо перемещено.</div>';
                smcLoadList();
            };
            window.smcDeleteForever = async function(id) {
                if (!confirm('Удалить письмо навсегда?')) return;
                await api('delete_forever', {id});
                currentId = null;
                document.getElementById('smc-reader').innerHTML = '<div class="smc-empty">Письмо удалено.</div>';
                smcLoadList();
            };

            window.smcSendReply = async function() {
                if (!currentMsg) return;
                const btn = document.getElementById('smc-r-btn');
                const st = document.getElementById('smc-r-status');
                const to = currentMsg.folder === 'sent' ? currentMsg.to_email : (currentMsg.reply_to || currentMsg.from_email);
                const body = document.getElementById('smc-r-body').value.trim();
                if (!body) { st.className = 'smc-notice err'; st.textContent = 'Введите текст ответа'; return; }
                btn.disabled = true; btn.textContent = 'Отправка...';
                try {
                    const res = await api('send', {
                        to_email: to,
                        subject: document.getElementById('smc-r-subject').value.trim(),
                        body_text: body,
                        from_email: document.getElementById('smc-r-from').value,
                        in_reply_to: currentMsg.message_id || ''
                    });
                    if (res.ok) {
                        st.className = 'smc-notice ok';
                        st.textContent = '✅ Ответ отправлен и сохранён в «Отправленные»!';
                        document.getElementById('smc-r-body').value = '';
                    } else {
                        st.className = 'smc-notice err';
                        st.textContent = '⚠️ ' + (res.error || 'Ошибка отправки') + ' (сохранено в «Отправленные» с пометкой ошибки)';
                    }
                    smcLoadList();
                } catch (e) {
                    st.className = 'smc-notice err'; st.textContent = e.message;
                } finally {
                    btn.disabled = false; btn.textContent = '📤 Отправить ответ';
                }
            };

            window.smcOpenCompose = function() {
                document.getElementById('smc-c-status').className = 'smc-notice';
                document.getElementById('smc-compose-modal').style.display = 'flex';
            };
            window.smcCloseCompose = function() { document.getElementById('smc-compose-modal').style.display = 'none'; };
            window.smcSendCompose = async function() {
                const btn = document.getElementById('smc-c-btn');
                const st = document.getElementById('smc-c-status');
                btn.disabled = true; btn.textContent = 'Отправка...';
                try {
                    const res = await api('send', {
                        from_email: document.getElementById('smc-c-from').value,
                        to_email: document.getElementById('smc-c-to').value.trim(),
                        subject: document.getElementById('smc-c-subject').value.trim(),
                        body_text: document.getElementById('smc-c-body').value.trim()
                    });
                    if (res.ok) {
                        st.className = 'smc-notice ok';
                        st.textContent = '✅ Письмо отправлено!';
                        document.getElementById('smc-c-to').value = '';
                        document.getElementById('smc-c-subject').value = '';
                        document.getElementById('smc-c-body').value = '';
                        smcLoadList();
                    } else {
                        st.className = 'smc-notice err';
                        st.textContent = '⚠️ ' + (res.error || 'Ошибка отправки');
                    }
                } catch (e) {
                    st.className = 'smc-notice err'; st.textContent = e.message;
                } finally {
                    btn.disabled = false; btn.textContent = '📤 Отправить';
                }
            };

            smcLoadList();
        })();
        </script>
        <?php
    }
}

Site_Mail_Console::init();
