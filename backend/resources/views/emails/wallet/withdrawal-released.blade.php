@extends('emails.layout')

@section('content')
    <p style="margin:0 0 12px;">Hi {{ $recipientName }},</p>
    <p style="margin:0 0 12px;">{{ $introLine ?? 'Your withdrawal request has been released. The funds should reflect in your account shortly, depending on your payout method.' }}</p>

    @include('emails.partials.details', ['rows' => $rows])

    <p style="margin:16px 0 0;">{{ $closingLine ?? 'Thanks for selling on FishMarket!' }}</p>
@endsection
