@extends('emails.layout')

@section('content')
    <p style="margin:0 0 12px;">Hi {{ $sellerName }},</p>
    <p style="margin:0 0 12px;">Your municipality's LGU has reviewed your listing and it was not approved this time.</p>

    @include('emails.partials.details', ['rows' => $rows])

    <div style="margin:16px 0 0;padding:14px 16px;border-radius:10px;background-color:#fef3c7;border:1px solid #fde68a;">
        <p style="margin:0;font-size:13px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:0.04em;">Reason</p>
        <p style="margin:6px 0 0;font-size:14px;color:#78350f;">{{ $reason }}</p>
    </div>

    <p style="margin:16px 0 0;">You're welcome to update the listing details and resubmit it for review.</p>
@endsection
