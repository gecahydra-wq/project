@extends('emails.layout')

@section('content')
    <p style="margin:0 0 12px;">Hi {{ $sellerName }},</p>
    <p style="margin:0 0 12px;">The completed order payment has been approved. Your {{ $sellerPercent }}% Seller Share has been transferred from your <strong>Pending Balance</strong> to your <strong>Available Balance</strong>.</p>

    @include('emails.partials.details', ['rows' => $rows])

    <div style="margin:16px 0 0;padding:14px 16px;border-radius:10px;background-color:#e0f2fe;border:1px solid #bae6fd;">
        <p style="margin:0;font-size:13px;color:#075985;">This is <strong>not</strong> a bank or e-wallet payout yet -- it simply unlocks the amount in your wallet. When you're ready, request a withdrawal from your Wallet tab; you'll get a separate email once the Super Admin releases the payout.</p>
    </div>
@endsection
