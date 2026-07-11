@extends('emails.layout')

@section('content')
    <p style="margin:0 0 12px;">Hi there,</p>
    <p style="margin:0 0 12px;">Your payment has been captured and funds are now held in escrow. Please prepare the order below for delivery.</p>

    @include('emails.partials.details', ['rows' => $rows])

    <p style="margin:16px 0 0;">Update the order's status from your dashboard as you confirm, ship, and complete it -- your buyer is notified automatically at each step.</p>
@endsection
