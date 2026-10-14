@extends('emails.layout')

@section('content')
    <p style="margin:0 0 12px;">Hi there,</p>
    <p style="margin:0 0 12px;">The buyer cancelled this order, so it will not be delivered. The fingerlings it had reserved are back in your listing. If the buyer had already paid, the payment is being refunded by the Super Admin -- it will not enter your Pending Balance or earnings approval.</p>

    @include('emails.partials.details', ['rows' => $rows])

    <p style="margin:16px 0 0;">No action is needed from you. You can review the order any time from your Orders tab.</p>
@endsection
