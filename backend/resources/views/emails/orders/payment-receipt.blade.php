@extends('emails.layout')

@section('content')
    <p style="margin:0 0 12px;">Hi {{ $buyerName }},</p>
    <p style="margin:0 0 12px;">Thanks for your order! We've received your payment and it's now held securely in escrow until your delivery is confirmed.</p>

    @include('emails.partials.details', ['rows' => $rows])

    <p style="margin:16px 0 0;">We'll let you know as soon as your seller confirms the order.</p>
@endsection
