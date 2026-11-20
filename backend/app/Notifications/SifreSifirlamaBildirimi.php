<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Şifre sıfırlama e-postası (Türkçe): yalnızca tek kullanımlık, 60 dakika geçerli bağlantı içerir; başka hiçbir sır yok.
 */
class SifreSifirlamaBildirimi extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $adres = url('/sifre-sifirla').'?'.http_build_query(['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);

        return (new MailMessage)
            ->subject('Finans: şifre sıfırlama')
            ->greeting('Merhaba '.$notifiable->ad.',')
            ->line('Hesabınız için şifre sıfırlama isteği aldık.')
            ->action('Şifremi sıfırla', $adres)
            ->line('Bağlantı 60 dakika geçerlidir ve bir kez kullanılabilir. İstek size ait değilse bu e-postayı yok sayın; şifreniz değişmez.')
            ->salutation('Finans');
    }
}
