<?php

namespace App\Helpers;

use App\Classes\PDF;
use App\Helpers\DiscordNotificationHelper;
use App\Mail\Mail;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\TicketMessage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail as FacadesMail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\View\Compilers\BladeCompiler;

class NotificationHelper
{
    /**
     * Send an email notification.
     */
    public static function sendEmailNotification(
        NotificationTemplate $notificationTemplate,
        array $data,
        User $user,
        array $attachments = []
    ): void {
        $mail = new Mail($notificationTemplate, $data);

        $emailLog = EmailLog::create([
            'user_id' => $user->id,
            'subject' => $mail->envelope()->subject,
            'to' => $user->email,
            'body' => $mail->render(),
        ]);

        // Add the email log id to the payload
        $mail->email_log_id = $emailLog->id;

        foreach ($attachments as $attachment) {
            $mail->attachFromStorage($attachment['path'], $attachment['name'], $attachment['options'] ?? []);
        }

        FacadesMail::to($user->email)
            ->bcc($notificationTemplate->bcc)
            ->cc($notificationTemplate->cc)
            ->queue($mail);
    }

    public static function sendSystemEmailNotification(
        string $subject,
        string $body,
        array $attachments = [],
        ?string $email = null,
    ): void {
        if (!$email) {
            $email = config('settings.system_email_address');
        }
        if (!$email || config('settings.mail_disable')) {
            return;
        }
        $mail = new \App\Mail\SystemMail([
            'subject' => $subject,
            'body' => $body,
        ]);
        $emailLog = EmailLog::create([
            'subject' => $mail->envelope()->subject,
            'to' => $email,
            'body' => $mail->render(),
        ]);

        // Add the email log id to the payload
        $mail->email_log_id = $emailLog->id;

        foreach ($attachments as $attachment) {
            $mail->attachFromStorage($attachment['path'], $attachment['name'], $attachment['options'] ?? []);
        }

        FacadesMail::to($email)
            ->queue($mail);
    }

    public static function sendInAppNotification(
        NotificationTemplate $notification,
        array $data,
        User $user,
        bool $show_in_app = true,
        bool $show_as_push = true
    ): void {
        Notification::create([
            'user_id' => $user->id,
            'title' => BladeCompiler::render($notification->in_app_title, $data),
            'body' => BladeCompiler::render($notification->in_app_body, $data),
            'url' => isset($notification->in_app_url) ? BladeCompiler::render($notification->in_app_url, $data) : null,
            'show_in_app' => $show_in_app,
            'show_as_push' => $show_as_push,
        ]);
    }

    public static function sendDiscordNotification(
        NotificationTemplate $notification,
        array $data,
        User $user
    ): void {
        $title = BladeCompiler::render($notification->subject, $data);
        $bodyRaw = BladeCompiler::render($notification->body, $data);

        // Extract hyperlinks before stripping HTML tags
        $buttonUrl = null;
        $buttonLabel = 'View Details';

        // Look for <a> tags in the HTML content
        if (preg_match('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>([^<]+)<\/a>/i', $bodyRaw, $matches)) {
            $buttonUrl = $matches[1];
            $buttonLabel = strip_tags($matches[2]);
            // Remove the hyperlink from the body
            $bodyRaw = preg_replace('/<a[^>]+href=["\'][^"\']+["\'][^>]*>[^<]+<\/a>/i', '', $bodyRaw);
        }

        // Strip HTML tags and convert to plain text for Discord
        $body = strip_tags($bodyRaw);

        // Check if the body contains a markdown table
        $hasTable = preg_match('/\|\s*[:-]+\s*\|\s*[:-]+\s*\|/', $body);

        if ($hasTable) {
            // For tables, preserve line breaks but clean up excessive whitespace
            // Convert multiple spaces to single space, but keep newlines
            $body = preg_replace('/[^\S\n]+/', ' ', $body);
            $body = preg_replace('/\n\s+/', "\n", $body); // Remove leading spaces on lines
            $body = trim($body);

            // Format table columns to align properly
            $body = self::formatTableForDiscord($body);

            // Wrap the entire message in a codeblock
            $body = "```\n" . $body . "\n```";
        } else {
            // For regular messages, clean up all extra whitespace
            $body = preg_replace('/\s+/', ' ', $body);
            $body = trim($body);
        }

        // Create embed fields if we have data that can be formatted
        $embedFields = [];

        // Add common fields like invoice/order/service info if available
        // IMPORTANT: All values must be simple strings to avoid Discord's "over 9 levels deep" error
        if (isset($data['invoice'])) {
            $invoice = $data['invoice'];
            $embedFields[] = [
                'name' => 'Invoice',
                'value' => (string) ('#' . ($invoice->number ?? $invoice->id)),
                'inline' => true,
            ];

            // Get the formatted total as a string
            $total = $invoice->formattedTotal;
            // If it's an object, try to convert it to string
            if (is_object($total)) {
                $total = method_exists($total, '__toString') ? (string) $total : json_encode($total);
            }

            $embedFields[] = [
                'name' => 'Amount',
                'value' => (string) $total,
                'inline' => true,
            ];
        }

        if (isset($data['order'])) {
            $embedFields[] = [
                'name' => 'Order',
                'value' => (string) ('#' . $data['order']->id),
                'inline' => true,
            ];
        }

        if (isset($data['service'])) {
            $embedFields[] = [
                'name' => 'Service',
                'value' => (string) $data['service']->product->name,
                'inline' => true,
            ];
        }

        DiscordNotificationHelper::sendNotification($user, $body, $title, $embedFields, $buttonUrl, $buttonLabel);
    }

    public static function sendNotification(
        $notificationTemplateKey,
        array $data,
        User $user,
        array $attachments = [],
        bool $show_in_app = true,
        bool $show_as_push = true
    ): void {
        $notification = NotificationTemplate::where('key', $notificationTemplateKey)->first();
        if (!$notification || !$notification->enabled) {
            return;
        }

        $userPreference = $user->notificationsPreferences()->where('notification_template_id', $notification->id)->first();

        if ($notification->isEnabledForPreference($userPreference, 'mail') && !config('settings.mail_disable')) {
            self::sendEmailNotification($notification, $data, $user, $attachments);
        }

        if ($notification->isEnabledForPreference($userPreference, 'app')) {
            self::sendInAppNotification($notification, $data, $user, $show_in_app, $show_as_push);
        }

        // Discord notification debug logging
        $discordEnabled = $notification->isEnabledForPreference($userPreference, 'discord');
        $canSend = DiscordNotificationHelper::canSendNotification($user);
        logger()->debug('Discord notification check', [
            'user_id' => $user->id,
            'notification_key' => $notificationTemplateKey,
            'discord_enabled_for_preference' => $discordEnabled,
            'can_send_notification' => $canSend,
            'discord_user_id' => $user->discord_user_id,
            'bot_token_set' => !empty(config('settings.discord_bot_token')),
            'notifications_enabled_in_settings' => config('settings.discord_notifications_enabled'),
        ]);

        if ($discordEnabled && $canSend) {
            logger()->debug('Attempting to send Discord notification', ['user_id' => $user->id, 'key' => $notificationTemplateKey]);
            self::sendDiscordNotification($notification, $data, $user);
        }
    }

    public static function loginDetectedNotification(User $user, array $data = []): void
    {
        self::sendNotification('new_login_detected', $data, $user);
    }

    public static function invoiceNotification(User $user, Invoice $invoice, $key = 'new_invoice_created'): void
    {
        logger()->debug('invoiceNotification started', ['invoice_id' => $invoice->id, 'user_id' => $user->id]);

        $data = [
            'invoice' => $invoice,
            'items' => $invoice->items,
            'total' => $invoice->formattedTotal,
            'has_subscription' => $invoice->items->filter(fn ($item) => $item->reference_type === Service::class && $item->reference->subscription_id)->isNotEmpty(),
            'due_at_formatted' => $invoice->due_at ? Carbon::parse($invoice->due_at)->format('F j, Y') : 'Not set',
        ];

        logger()->debug('Generating PDF for invoice', ['invoice_id' => $invoice->id]);
        $pdf = PDF::generateInvoice($invoice);

        // Generate path
        $invoiceDir = storage_path('app/invoices');
        if (!file_exists($invoiceDir)) {
            logger()->debug('Invoices directory does not exist, creating...', ['dir' => $invoiceDir]);
            mkdir($invoiceDir, 0755, true);
        }

        // Make sure it's writable
        if (!is_writable($invoiceDir)) {
            logger()->warning('Invoices directory is not writable, changing permissions...', ['dir' => $invoiceDir]);
            chmod($invoiceDir, 0775);
        }

        // Save the PDF
        $pdfPath = storage_path('app/invoices/' . ($invoice->number ?? $invoice->id) . '.pdf');
        logger()->debug('Saving PDF', ['pdf_path' => $pdfPath]);
        $pdfContent = $pdf->output(); // Get raw PDF bytes

        try {
            Storage::put('invoices/' . ($invoice->number ?? $invoice->id) . '.pdf', $pdfContent);
            logger()->debug('PDF saved successfully', ['pdf_path' => $pdfPath, 'size' => strlen($pdfContent)]);
        } catch (\Exception $e) {
            logger()->error('Failed to save PDF', [
                'pdf_path' => $pdfPath,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // Attach the PDF to the email
        $attachments = [
            [
                'path' => 'invoices/' . ($invoice->number ?? $invoice->id) . '.pdf',
                'name' => 'invoice.pdf',
            ],
        ];

        logger()->debug('Sending notification', ['key' => $key, 'user_id' => $user->id]);
        self::sendNotification($key, $data, $user, $attachments);
        logger()->debug('invoiceNotification finished', ['invoice_id' => $invoice->id]);
    }

    public static function invoiceCreatedNotification(User $user, Invoice $invoice): void
    {
        self::invoiceNotification($user, $invoice, 'new_invoice_created');
    }

    public static function invoicePaidNotification(User $user, Invoice $invoice): void
    {
        self::invoiceNotification($user, $invoice, 'invoice_paid');
    }

    public static function invoiceRemindNotification(User $user, Invoice $invoice): void
    {
        self::invoiceNotification($user, $invoice, 'invoice_reminder');
    }

    public static function invoicePaymentFailedNotification(User $user, Invoice $invoice): void
    {
        self::invoiceNotification($user, $invoice, 'invoice_payment_failed');
    }

    public static function orderCreatedNotification(User $user, Order $order, array $data = []): void
    {
        $data = [
            'order' => $order,
            'items' => $order->services,
            'total' => $order->formattedTotal,
        ];
        self::sendNotification('new_order_created', $data, $user);
    }

    public static function serverCreatedNotification(User $user, Service $service, array $data = []): void
    {
        $data['service'] = $service;
        self::sendNotification('new_server_created', $data, $user);
    }

    public static function serverSuspendedNotification(User $user, Service $service, array $data = []): void
    {
        $data['service'] = $service;
        self::sendNotification('server_suspended', $data, $user);
    }

    public static function serverTerminatedNotification(User $user, Service $service, array $data = []): void
    {
        $data['service'] = $service;
        self::sendNotification('server_terminated', $data, $user);
    }

    public static function ticketMessageNotification(User $user, TicketMessage $ticketMessage, array $data = []): void
    {
        $data['ticketMessage'] = $ticketMessage;
        self::sendNotification('new_ticket_message', $data, $user);
    }

    public static function emailVerificationNotification(User $user, array $data = []): void
    {
        $cacheKey = 'email_verification_sent:' . $user->id;

        // Block resend if still within cooldown (15 minutes)
        if (Cache::has($cacheKey)) {
            return;
        }

        $expireTime = Config::get('auth.verification.expire', 15);
        // Mark as sent for 15 minutes
        Cache::put($cacheKey, true, $expireTime);

        $data['user'] = $user;
        $data['url'] = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes($expireTime),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->email),
            ]
        );

        $data['expire_time'] = $expireTime;

        self::sendNotification('email_verification', $data, $user);
    }

    public static function passwordResetNotification(User $user, array $data = []): void
    {
        $data['user'] = $user;
        self::sendNotification('password_reset', $data, $user);
    }

    public static function serviceCancellationReceivedNotification(User $user, ServiceCancellation $cancellation, array $data = []): void
    {
        $data['cancellation'] = $cancellation;
        $data['service'] = $cancellation->service;
        self::sendNotification('service_cancellation_received', $data, $user);
    }

    /**
     * Format a markdown table for Discord by aligning columns properly
     */
    private static function formatTableForDiscord(string $tableText): string
    {
        $lines = explode("\n", $tableText);
        $tableLines = [];
        $columnWidths = [];

        // First pass: collect all table rows and calculate column widths
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Check if this is a table row (starts and ends with |)
            if (strpos($line, '|') === 0 && strrpos($line, '|') === strlen($line) - 1) {
                $cells = array_map('trim', explode('|', trim($line, '|')));
                $tableLines[] = $cells;

                // Update column widths
                foreach ($cells as $colIndex => $cell) {
                    $width = strlen($cell);
                    if (!isset($columnWidths[$colIndex]) || $width > $columnWidths[$colIndex]) {
                        $columnWidths[$colIndex] = $width;
                    }
                }
            } else {
                // Non-table line, add as-is
                $tableLines[] = $line;
            }
        }

        // Second pass: format each row with proper alignment
        $formattedLines = [];
        foreach ($tableLines as $row) {
            if (is_array($row)) {
                // This is a table row
                $formattedCells = [];
                foreach ($row as $colIndex => $cell) {
                    $width = $columnWidths[$colIndex] ?? strlen($cell);
                    $formattedCells[] = str_pad($cell, $width, ' ', STR_PAD_BOTH);
                }
                $formattedLines[] = '| ' . implode(' | ', $formattedCells) . ' |';
            } else {
                // Non-table line
                $formattedLines[] = $row;
            }
        }

        return implode("\n", $formattedLines);
    }
}
