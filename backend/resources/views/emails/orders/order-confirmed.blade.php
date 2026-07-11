@extends('emails.layout')

@section('content')
    <p style="margin:0 0 12px;">Hi {{ $buyerName }},</p>
    <p style="margin:0 0 12px;">Good news -- your seller has received and confirmed your order. They're now preparing your fingerlings for delivery.</p>

    @include('emails.partials.details', ['rows' => $rows])

    <p style="margin:16px 0 0;"><strong>What's next:</strong> your seller will coordinate delivery timing and location directly with you. You can follow the order's progress any time from your Orders tab, and we'll email you again once it's marked delivered.</p>
@endsection
