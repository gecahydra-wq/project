@extends('emails.layout')

@section('content')
    <p style="margin:0 0 12px;">Hi {{ $buyerName }},</p>
    <p style="margin:0 0 12px;">Your seller has confirmed that your order was delivered. We hope your fingerlings arrived happy and healthy!</p>

    @include('emails.partials.details', ['rows' => $rows])

    <p style="margin:16px 0 0;">Got a minute? Sharing a quick review helps other buyers choose the right seller -- and helps good sellers get recognized.</p>
@endsection
