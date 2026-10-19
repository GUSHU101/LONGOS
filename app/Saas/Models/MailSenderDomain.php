<?php

namespace App\Saas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Explicitly assigns one relay-signed From domain to one LONGOS tenant.
 *
 * Storefront routing domains and sender domains are deliberately separate:
 * www.example.com may be the shop host while example.com is the From domain.
 */
class MailSenderDomain extends Model
{
    protected $connection = 'saas';

    protected $table = 'saas_mail_sender_domains';

    protected $fillable = ['site_id', 'domain'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
