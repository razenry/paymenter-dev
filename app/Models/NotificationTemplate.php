<?php

namespace App\Models;

use App\Enums\NotificationEnabledStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

class NotificationTemplate extends Model implements Auditable
{
    use \App\Models\Traits\Auditable, HasFactory;

    protected $fillable = [
        'key',
        'name',
        'subject',
        'enabled',
        'body',
        'cc',
        'bcc',
        'mail_enabled',
        'in_app_enabled',
        'discord_enabled',
        'in_app_title',
        'in_app_body',
        'edit_preference_message',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'cc' => 'array',
        'bcc' => 'array',
        'mail_enabled' => NotificationEnabledStatus::class,
        'in_app_enabled' => NotificationEnabledStatus::class,
        'discord_enabled' => NotificationEnabledStatus::class,
    ];

    public function preferences()
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function isEmailUserControllable()
    {
        return in_array($this->mail_enabled, [NotificationEnabledStatus::ChoiceOn, NotificationEnabledStatus::ChoiceOff]);
    }

    public function isInAppUserControllable()
    {
        return in_array($this->in_app_enabled, [NotificationEnabledStatus::ChoiceOn, NotificationEnabledStatus::ChoiceOff]);
    }

    public function isDiscordUserControllable()
    {
        return in_array($this->discord_enabled, [NotificationEnabledStatus::ChoiceOn, NotificationEnabledStatus::ChoiceOff]);
    }

    // Check if user has enabled this notification for email
    public function isEnabledForPreference(?NotificationPreference $preference = null, $type = 'mail')
    {
        $fieldMap = [
            'mail' => 'mail_enabled',
            'app' => 'in_app_enabled',
            'discord' => 'discord_enabled',
        ];

        $field = $fieldMap[$type] ?? 'mail_enabled';

        if ($this->{$field} === NotificationEnabledStatus::Force) {
            return true;
        }
        if ($this->{$field} === NotificationEnabledStatus::Never) {
            return false;
        }

        if ($preference) {
            return $preference->{$field};
        }

        // Return true if choice_on, false if choice_off
        return $this->{$field} === NotificationEnabledStatus::ChoiceOn;
    }
}
