<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An evidence photo attached to a RefundRequest. Mirrors App\Models\ListingMedia
 * (type/title/url/position) so the existing ImageUploader pipeline and the
 * frontend gallery are reused unchanged; evidence is optional, so a request may
 * have none.
 */
class RefundRequestMedia extends Model
{
    protected $table = 'refund_request_media';

    protected $fillable = ['refund_request_id', 'type', 'title', 'url', 'position'];

    public function refundRequest()
    {
        return $this->belongsTo(RefundRequest::class);
    }
}
