@extends('emails.layout')

@section('content')
    <p style="margin:0 0 12px;">Hi {{ $sellerName }},</p>
    <p style="margin:0 0 12px;">Good news -- your local LGU has reviewed and approved your listing. It's now visible to buyers on the AbaiMarket marketplace.</p>

    @include('emails.partials.details', ['rows' => $rows])

    <p style="margin:16px 0 0;">Keep your stock quantity up to date so buyers always see accurate availability.</p>
@endsection
